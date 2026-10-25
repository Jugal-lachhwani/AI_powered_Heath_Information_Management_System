"""
pipeline/base_intake_session.py — Shared orchestration for AI outbound intake
call sessions (mocked telephony).

IntakeCallSession (fixed canned scripts) and DynamicIntakeCallSession
(doctor-authored steps + LLM-generated agent/patient turns) share everything
here — connection lifecycle, control-frame handling, whisper/barge-in/hold
coordination, per-chunk extraction + red-flag checking, and finalize. The one
thing each subclass owns itself is HOW the conversation content for a turn is
produced (_pace_script) — that's the actual difference between "fixed" and
"dynamic" mode, so it's the only method left abstract here.
"""
from __future__ import annotations

import asyncio
import json
import logging
from typing import Any

from fastapi import WebSocket, WebSocketDisconnect

from ..config import Settings
from ..llm.client import LLMClient
from ..models.intake import IntakeExtraction
from ..models.intake_state import IntakeState
from ..observing.run_logger import make_run_id
from ..pipeline.llm_processor import LLMProcessor

logger = logging.getLogger(__name__)


class BaseIntakeCallSession:
    """Not instantiated directly — see IntakeCallSession / DynamicIntakeCallSession."""

    mode = "base"  # overridden by subclasses; included in the final payload

    def __init__(self, websocket: WebSocket, settings: Settings, llm_client: LLMClient) -> None:
        self.ws = websocket
        self.settings = settings
        self.run_id = make_run_id()

        self.processor = LLMProcessor(llm_client=llm_client, prompt_version=settings.prompt_version)
        self.state = IntakeState()

        self.transcript_turns: list[dict[str, Any]] = []
        self.red_flags: list[dict[str, Any]] = []
        self.tasks: list[dict[str, Any]] = []
        self.current_step = "identity"
        self.human_taken_over = False

        self._pending_chunk_turns: list[dict[str, Any]] = []
        self._chunk_index = 0

        # Control-frame coordination — see _interruptible_sleep()/_receive_control()
        self._stop_event = asyncio.Event()  # barge_in or end -> stop playback permanently
        self._wake_event = asyncio.Event()  # set on any control frame, interrupts the current sleep
        self._held = False
        self._pending_whisper: str | None = None
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

    # ------------------------------------------------------------------
    # Subclass responsibility
    # ------------------------------------------------------------------

    async def run(self) -> None:
        raise NotImplementedError

    async def _pace_script(self) -> None:
        raise NotImplementedError

    def _handle_extra_control(self, ctype: str | None, control: dict[str, Any]) -> bool:
        """Hook for subclass-specific control frames (e.g. dynamic mode's "init").
        Return True if fully handled — skips the generic call_status echo below,
        since a handshake frame isn't really a "call status change"."""
        return False

    # ------------------------------------------------------------------
    # Control-frame receive loop (background task) — shared
    # ------------------------------------------------------------------

    async def _receive_control(self) -> None:
        while True:
            try:
                message = await self.ws.receive()
            except WebSocketDisconnect:
                self._stop_event.set()
                self._wake_event.set()
                return

            if message.get("type") == "websocket.disconnect":
                self._stop_event.set()
                self._wake_event.set()
                return

            text = message.get("text")
            if not text:
                continue

            try:
                control = json.loads(text)
            except json.JSONDecodeError:
                continue

            ctype = control.get("type")

            if self._handle_extra_control(ctype, control):
                self._wake_event.set()
                continue

            if ctype == "whisper":
                self._pending_whisper = str(control.get("text", ""))[:500]
            elif ctype == "barge_in":
                self.human_taken_over = True
                self._stop_event.set()
            elif ctype == "hold":
                self._held = True
            elif ctype == "resume":
                self._held = False
            elif ctype == "end":
                self._stop_event.set()
            # "mute"/"unmute" have no real audio to affect in this mock — status-only,
            # reflected back below so the UI can update, no internal state change needed.

            await self._send_json({
                "type": "call_status",
                "run_id": self.run_id,
                "status": "held" if self._held else "in_progress",
                "human_taken_over": self.human_taken_over,
                "current_step": self.current_step,
            })
            self._wake_event.set()

    # ------------------------------------------------------------------
    # Pacing helpers — shared
    # ------------------------------------------------------------------

    async def _interruptible_sleep(self, seconds: float) -> None:
        self._wake_event.clear()
        try:
            await asyncio.wait_for(self._wake_event.wait(), timeout=seconds)
        except asyncio.TimeoutError:
            pass

    async def _wait_while_held(self) -> None:
        """Block here (interruptibly) while a hold is active. Caller must still
        check self._stop_event immediately after this returns."""
        while self._held and not self._stop_event.is_set():
            self._wake_event.clear()
            await self._wake_event.wait()

    # ------------------------------------------------------------------
    # Per-chunk extraction + red-flag check — shared
    # ------------------------------------------------------------------

    async def _process_pending_chunk(self) -> None:
        if not self._pending_chunk_turns:
            return

        self._chunk_index += 1
        extraction_input = "\n".join(
            f"{t['speaker']}: {t['text']}" for t in self._pending_chunk_turns
        )
        self._pending_chunk_turns = []

        patch, _metrics = await self.processor.process_intake_chunk(
            chunk_index=self._chunk_index,
            transcript=extraction_input,
            current_state=self.state.to_canonical_json(),
        )
        self._record_usage("intake_extraction", _metrics)
        if patch:
            self.state.apply_patch(
                patch=patch, chunk_index=self._chunk_index, evidence=extraction_input[:300]
            )

        new_flags, _metrics = await self.processor.check_red_flags(self.transcript_turns)
        self._record_usage("red_flag_check", _metrics)
        newly_added = self._merge_new_red_flags(new_flags)

        await self._send_json({
            "type": "interim",
            "run_id": self.run_id,
            "step": self.current_step,
            "state": self.state.to_canonical_json(),
            "transcript_turns": self.transcript_turns,
            "red_flags": self.red_flags,
            "tasks": self.tasks,
            "usage": self._usage_snapshot(),
        })

        for flag in newly_added:
            await self._send_json({"type": "red_flag", "run_id": self.run_id, "flag": flag})

    def _merge_new_red_flags(self, flags: list[dict[str, Any]]) -> list[dict[str, Any]]:
        """Dedupe by flag type (the watch-category set is small and fixed) and, for any
        genuinely new flag, auto-add its suggested_task to the tasks list."""
        seen_types = {f.get("type") for f in self.red_flags}
        newly_added: list[dict[str, Any]] = []
        for flag in flags:
            flag_type = flag.get("type")
            if not flag_type or flag_type in seen_types:
                continue
            self.red_flags.append(flag)
            seen_types.add(flag_type)
            newly_added.append(flag)
            suggested = flag.get("suggested_task")
            if suggested:
                self.tasks.append({
                    "text": suggested, "source": "red_flag",
                    "section": "", "priority": "high",
                })
        return newly_added

    # ------------------------------------------------------------------
    # Finalize — shared
    # ------------------------------------------------------------------

    async def _finalize(self) -> None:
        logger.info("[run=%s] Building final intake result", self.run_id)

        final_flags, _metrics = await self.processor.check_red_flags(self.transcript_turns)
        self._record_usage("final_red_flag_check", _metrics)
        self._merge_new_red_flags(final_flags)

        canonical = self.state.to_canonical_json()

        # Code-derived tasks: any section with a genuine but low (not empty) confidence.
        for key, section in canonical.items():
            if not isinstance(section, dict):
                continue
            confidence = section.get("confidence", 0.0)
            if 0.0 < confidence < 0.5:
                self.tasks.append({
                    "text": f"Verify {key.replace('_', ' ')} — low confidence",
                    "source": "low_confidence", "section": key, "priority": "normal",
                })

        try:
            extraction = IntakeExtraction.model_validate({
                **canonical, "red_flags": self.red_flags, "tasks": self.tasks,
            })
            final_output = extraction.model_dump()
        except Exception as exc:
            logger.warning("[run=%s] Final validation failed: %s. Using raw state.", self.run_id, exc)
            final_output = {**canonical, "red_flags": self.red_flags, "tasks": self.tasks}

        final_output["transcript_turns"] = self.transcript_turns
        final_output["human_taken_over"] = self.human_taken_over
        final_output["current_step"] = self.current_step
        final_output["mode"] = self.mode
        final_output["script_key"] = getattr(self, "script_key", None)
        final_output["_usage"] = self._usage_snapshot(final=True)

        await self._send_json({
            "type": "final",
            "run_id": self.run_id,
            "result": final_output,
            "usage": final_output["_usage"],
        })

    # ------------------------------------------------------------------
    # Usage accounting — shared
    # ------------------------------------------------------------------

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

    def _record_failed_usage(self, label: str) -> None:
        self.usage["failed_calls"] += 1
        by_label = self.usage["by_label"]
        row = by_label.setdefault(label, {
            "api_calls": 0,
            "input_tokens": 0,
            "output_tokens": 0,
            "total_tokens": 0,
            "total_time_seconds": 0.0,
        })
        row["failed_calls"] = int(row.get("failed_calls") or 0) + 1

    def _usage_snapshot(self, final: bool = False) -> dict[str, Any]:
        return {
            **self.usage,
            "final": final,
            "calculation": "estimated_from_text_chars_div_4",
        }

    # ------------------------------------------------------------------
    # WebSocket message helpers — shared
    # ------------------------------------------------------------------

    async def _send_error(self, message: str) -> None:
        await self._send_json({"type": "error", "run_id": self.run_id, "message": message})

    async def _send_json(self, data: dict[str, Any]) -> None:
        try:
            await self.ws.send_text(json.dumps(data, ensure_ascii=False))
        except Exception as exc:
            logger.warning("[run=%s] Could not send WS message: %s", self.run_id, exc)
