"""
pipeline/session.py — WebSocket session orchestrator.

One Session instance per connected WebSocket client.
Ties together: AudioBuffer -> STT -> LLMProcessor -> MedicalState -> RunLogger

WebSocket protocol:
  Client -> Server:
    - Binary frames: raw PCM audio bytes
    - Text frame: '{"type": "end"}' to signal end of audio

  Server -> Client:
    - '{"type": "interim", "chunk": N, "transcript": "...", "state": {...}, "transcript_turns": [...]}'
    - '{"type": "final", "run_id": "...", "result": {..., "transcript_turns": [...]}}'
    - '{"type": "error", "message": "..."}'
    - '{"type": "status", "message": "..."}' (progress updates)

  transcript_turns is the full, speaker-attributed (Doctor/Patient) transcript
  accumulated so far, structured by the LLM from the raw per-chunk STT text —
  sent as a full snapshot each time (like state), not a diff.
"""
from __future__ import annotations

import asyncio
import json
import logging
from typing import Any

from fastapi import WebSocket, WebSocketDisconnect

from ..config import Settings
from ..llm.client import LLMClient
from ..models.state import MedicalState
from ..models.clinical import ClinicalExtraction
from ..observing.run_logger import RunLogger, make_run_id
from ..pipeline.audio_buffer import AudioBuffer
from ..pipeline.llm_processor import LLMProcessor
from ..pipeline.stt import create_stt

logger = logging.getLogger(__name__)


class Session:
    """
    Manages one WebSocket session from connection to final output.
    """

    def __init__(
        self,
        websocket: WebSocket,
        settings: Settings,
        llm_client: LLMClient,
        source: str = "live",
    ) -> None:
        """
        source: "live" (default) for a live mic recording — audio arrives paced
            at roughly real-time speed, so Sarvam's real-time streaming STT
            (SarvamStreamingSTT) can keep up. "upload" for an already-recorded
            file — the client sends it far faster than real time (a whole
            recording in seconds), which the streaming API cannot transcribe
            correctly, so uploads use batch REST STT (SarvamSTT) instead, one
            full chunk per call, no pacing requirement.
        """
        self.ws = websocket
        self.settings = settings
        self.source = source
        self.is_streaming_stt = source != "upload"

        # Generate a unique run ID for this session
        self.run_id = make_run_id()

        # Core pipeline components
        self.buffer = AudioBuffer(
            chunk_seconds=settings.chunk_seconds,
            overlap_seconds=settings.overlap_seconds,
            sample_rate=settings.sample_rate,
            channels=settings.channels,
            bit_depth=settings.bit_depth,
        )
        self.stt = create_stt(
            api_key=settings.sarvam_api_key,
            language=settings.sarvam_language,
            model=settings.sarvam_model,
            sample_rate=settings.sample_rate,
            channels=settings.channels,
            bit_depth=settings.bit_depth,
            mode="streaming" if self.is_streaming_stt else "batch",
        )
        self.processor = LLMProcessor(
            llm_client=llm_client,
            prompt_version=settings.prompt_version,
        )
        self.state = MedicalState()
        self.transcript_turns: list[dict[str, Any]] = []
        self.usage: dict[str, Any] = {
            "provider": settings.llm_provider,
            "model": settings.llm_model,
            "input_tokens": 0,
            "output_tokens": 0,
            "total_tokens": 0,
            "api_calls": 0,
            "llm_api_calls": 0,
            "stt_api_calls": 0,
            "failed_calls": 0,
            "total_time_seconds": 0.0,
            "by_label": {},
        }
        self.logger = RunLogger(
            run_id=self.run_id,
            results_dir=settings.results_dir,
            metadata={
                "provider": settings.llm_provider,
                "model": settings.llm_model,
                "prompt_version": settings.prompt_version,
                "chunk_seconds": settings.chunk_seconds,
                "overlap_seconds": settings.overlap_seconds,
                "sample_rate": settings.sample_rate,
                "sarvam_model": settings.sarvam_model,
                "sarvam_language": settings.sarvam_language,
            },
        )

        logger.info("Session created: run_id=%s", self.run_id)

    # ------------------------------------------------------------------
    # Main entry point
    # ------------------------------------------------------------------

    async def run(self) -> None:
        """
        Main session loop. Runs until the client sends {"type":"end"} or disconnects.
        """
        await self._send_status(f"Session started. run_id={self.run_id}")

        # Open the persistent streaming STT connection for this session's lifetime.
        # Batch STT (file uploads) has no persistent connection to open.
        if self.is_streaming_stt:
            await self.stt.connect()
            self._record_stt_usage()

        # Chunk processing happens in a background task so audio ingestion is never blocked
        chunk_queue: asyncio.Queue = asyncio.Queue()
        processing_task = asyncio.create_task(self._process_chunks(chunk_queue))

        try:
            await self._receive_audio(chunk_queue)
        except WebSocketDisconnect:
            logger.info("[run=%s] Client disconnected", self.run_id)
        except Exception as exc:
            logger.exception("[run=%s] Unexpected error in receive loop", self.run_id)
            await self._send_error(str(exc))
        finally:
            # Signal the processing task we're done receiving
            await chunk_queue.put(None)  # type: ignore[arg-type]  # sentinel

        # Wait for all queued chunks to finish processing
        try:
            await asyncio.wait_for(processing_task, timeout=300.0)
        except asyncio.TimeoutError:
            logger.error("[run=%s] Processing task timed out", self.run_id)
            processing_task.cancel()

        # Send final result
        await self._finalize()

        # Cleanup
        await self.stt.close()

    # ------------------------------------------------------------------
    # Receive loop
    # ------------------------------------------------------------------

    async def _receive_audio(self, queue: asyncio.Queue) -> None:
        """
        Receive WebSocket frames from the client.
        - Binary frames: audio data.
            - Streaming STT (live mic): forwarded to the STT connection in real time;
              AudioBuffer is only used to detect chunk-boundary timing, its byte
              payload is discarded (audio was already streamed) — queue gets chunk_index.
            - Batch STT (upload): AudioBuffer's byte payload IS the chunk sent to STT
              later — queue gets (chunk_index, chunk_bytes).
        - Text frames: control messages ({"type": "end"})
        """
        while True:
            message = await self.ws.receive()

            if "bytes" in message and message["bytes"]:
                pcm_data: bytes = message["bytes"]

                if self.is_streaming_stt:
                    await self.stt.send_audio(pcm_data)
                    for chunk_index, _chunk_bytes in self.buffer.feed(pcm_data):
                        await queue.put(chunk_index)
                else:
                    for chunk_index, chunk_bytes in self.buffer.feed(pcm_data):
                        await queue.put((chunk_index, chunk_bytes))

            elif "text" in message and message["text"]:
                try:
                    control = json.loads(message["text"])
                    if control.get("type") == "end":
                        logger.info("[run=%s] Received end signal from client", self.run_id)
                        flush_result = self.buffer.flush()
                        if flush_result is not None:
                            chunk_index, chunk_bytes = flush_result
                            await queue.put(chunk_index if self.is_streaming_stt else (chunk_index, chunk_bytes))
                        return
                    else:
                        logger.debug("[run=%s] Unknown control message: %s", self.run_id, control)
                except json.JSONDecodeError:
                    logger.warning("[run=%s] Non-JSON text frame received", self.run_id)

            elif message.get("type") == "websocket.disconnect":
                raise WebSocketDisconnect()

    # ------------------------------------------------------------------
    # Processing loop (background task)
    # ------------------------------------------------------------------

    async def _process_chunks(self, queue: asyncio.Queue) -> None:
        """
        Background task: dequeues chunks and processes them one by one.
        Stops when it receives None (sentinel) from the queue.
        """
        while True:
            item = await queue.get()

            if item is None:  # Sentinel — no more chunks
                break

            if self.is_streaming_stt:
                chunk_index, chunk_bytes = item, None
            else:
                chunk_index, chunk_bytes = item
            await self._handle_one_chunk(chunk_index, chunk_bytes)
            queue.task_done()

    async def _handle_one_chunk(self, chunk_index: int, chunk_bytes: bytes | None) -> None:
        """Process one chunk boundary: STT -> structure+translate -> extract -> state update -> WS message."""
        logger.info("[run=%s] Processing chunk %d", self.run_id, chunk_index)

        await self._send_status(f"Processing chunk {chunk_index}...")

        # 1. STT — retrieve the native-language transcript for this window.
        #    Streaming (live mic): flush the persistent connection.
        #    Batch (upload): one-shot REST call on this chunk's raw bytes.
        if self.is_streaming_stt:
            transcript = await self.stt.flush_and_get_transcript()
        else:
            transcript = await self.stt.transcribe(chunk_bytes or b"")
            self._record_stt_usage()
        if not transcript:
            logger.warning("[run=%s] Chunk %d: empty transcript", self.run_id, chunk_index)
            await self._send_status(f"Chunk {chunk_index}: no speech detected")

        # 2. Structure into speaker turns + translate to English (must complete before
        #    extraction, since extraction now reads this step's output, not raw STT text)
        new_turns, _turn_metrics = await self.processor.structure_transcript_chunk(
            self.transcript_turns, transcript
        )
        self._record_usage("transcript_structuring", _turn_metrics)
        if new_turns:
            self.transcript_turns.extend(new_turns)

        # 3. Build the English, speaker-labeled text that extraction will read
        extraction_input = "\n".join(
            f"{turn.get('speaker', 'Unknown')}: {turn.get('text', '')}" for turn in new_turns
        )

        # 4. LLM clinical extraction — now fed translated, diarized text instead of raw STT
        patch, metrics = await self.processor.process_chunk(
            chunk_index=chunk_index,
            transcript=extraction_input,
            current_state=self.state.to_canonical_json(),
        )
        self._record_usage("clinical_extraction", metrics)

        # 5. Apply patch to state
        if patch:
            self.state.apply_patch(
                patch=patch,
                chunk_index=chunk_index,
                evidence=extraction_input[:300],  # Store first 300 chars as evidence
            )

        updated_state = self.state.to_canonical_json()

        # 6. Log to results directory
        self.logger.log_chunk(
            chunk_index=chunk_index,
            transcript=transcript,
            patch=patch,
            canonical_state=updated_state,
            internal_state=self.state.to_full_internal_state(),
            metrics=metrics,
            transcript_turns=self.transcript_turns,
        )

        # 7. Send interim update to client
        await self._send_interim(chunk_index, transcript, updated_state, self.transcript_turns)

    # ------------------------------------------------------------------
    # Finalize
    # ------------------------------------------------------------------

    async def _finalize(self) -> None:
        """Build and send the final result."""
        logger.info("[run=%s] Building final result", self.run_id)

        final_state = self.state.to_canonical_json()

        # Validate through the Pydantic model for a clean final output
        try:
            extraction = ClinicalExtraction.model_validate(final_state)
            final_output = extraction.model_dump()
        except Exception as exc:
            logger.warning("[run=%s] Final Pydantic validation failed: %s. Using raw state.", self.run_id, exc)
            final_output = final_state

        # Drug interaction check — runs once, only at finalize, never blocks the final send
        try:
            medications = final_output.get("prescription", {}).get("medications", [])
            interactions, _ = await self.processor.check_drug_interactions(medications)
            self._record_usage("drug_interaction_check", _)
            final_output["drug_interactions"] = interactions
        except Exception as exc:
            logger.warning("[run=%s] Drug interaction check failed: %s. Reporting no interactions.", self.run_id, exc)
            final_output["drug_interactions"] = []

        final_output["transcript_turns"] = self.transcript_turns
        final_output["_usage"] = self._usage_snapshot(final=True)

        # Log final result
        self.logger.log_final(final_output)

        # Send to client
        await self._send_final(final_output)

    # ------------------------------------------------------------------
    # WebSocket message helpers
    # ------------------------------------------------------------------

    async def _send_interim(
        self, chunk: int, transcript: str, state: dict[str, Any], transcript_turns: list[dict[str, Any]]
    ) -> None:
        await self._send_json({
            "type": "interim",
            "run_id": self.run_id,
            "chunk": chunk,
            "transcript": transcript,
            "state": state,
            "transcript_turns": transcript_turns,
            "usage": self._usage_snapshot(),
        })

    async def _send_final(self, result: dict[str, Any]) -> None:
        await self._send_json({
            "type": "final",
            "run_id": self.run_id,
            "result": result,
            "usage": result.get("_usage", self._usage_snapshot(final=True)),
        })

    async def _send_status(self, message: str) -> None:
        await self._send_json({"type": "status", "run_id": self.run_id, "message": message})

    async def _send_error(self, message: str) -> None:
        self.logger.log_error(message)
        await self._send_json({"type": "error", "run_id": self.run_id, "message": message})

    async def _send_json(self, data: dict[str, Any]) -> None:
        try:
            await self.ws.send_text(json.dumps(data, ensure_ascii=False))
        except Exception as exc:
            logger.warning("[run=%s] Could not send WS message: %s", self.run_id, exc)

    def _record_usage(self, label: str, metrics: dict[str, Any] | None) -> None:
        if not metrics:
            return

        input_tokens = int(metrics.get("est_input_tokens") or 0)
        output_tokens = int(metrics.get("est_output_tokens") or metrics.get("est_tokens") or 0)
        total_tokens = int(metrics.get("est_total_tokens") or input_tokens + output_tokens)
        total_time = float(metrics.get("TT") or 0.0)

        self.usage["input_tokens"] += input_tokens
        self.usage["output_tokens"] += output_tokens
        self.usage["total_tokens"] += total_tokens
        self.usage["api_calls"] += 1
        self.usage["llm_api_calls"] += 1
        self.usage["total_time_seconds"] = round(
            float(self.usage["total_time_seconds"]) + total_time, 4
        )

        by_label = self.usage["by_label"]
        row = by_label.setdefault(label, {
            "api_calls": 0,
            "input_tokens": 0,
            "output_tokens": 0,
            "total_tokens": 0,
            "total_time_seconds": 0.0,
        })
        row["api_calls"] += 1
        row["input_tokens"] += input_tokens
        row["output_tokens"] += output_tokens
        row["total_tokens"] += total_tokens
        row["total_time_seconds"] = round(float(row["total_time_seconds"]) + total_time, 4)

    def _usage_snapshot(self, final: bool = False) -> dict[str, Any]:
        return {
            **self.usage,
            "final": final,
            "calculation": "estimated_from_text_chars_div_4",
        }

    def _record_stt_usage(self) -> None:
        self.usage["stt_api_calls"] += 1
        self.usage["api_calls"] += 1
