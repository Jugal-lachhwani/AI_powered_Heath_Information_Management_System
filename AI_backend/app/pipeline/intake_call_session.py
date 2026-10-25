"""
pipeline/intake_call_session.py — Fixed-script AI outbound intake call (mocked telephony).

Plays a canned two-party script (mock_intake_scripts.py) turn by turn, pumping each
Patient turn through the SAME real extraction/red-flag LLM pipeline any live call
would use — only the conversation content is scripted, not the downstream processing.
See base_intake_session.py for everything shared with DynamicIntakeCallSession
(connection lifecycle, control frames, chunk processing, finalize).

WebSocket protocol:
  Client -> Server (text control frames):
    - '{"type": "whisper", "text": "..."}'   — logged + annotates the next Agent turn
    - '{"type": "barge_in"}'                  — stops scripted playback, marks human_taken_over
    - '{"type": "mute"}' / '{"type": "unmute"}'
    - '{"type": "hold"}' / '{"type": "resume"}'
    - '{"type": "end"}'                       — stops scripted playback early

  Server -> Client:
    - '{"type": "call_status", "status": "...", "human_taken_over": bool}'
    - '{"type": "turn", "step": "...", "speaker": "Agent"|"Patient", "text": "...", "whisper_annotation": "..."|null}'
    - '{"type": "interim", "step": "...", "state": {...}, "transcript_turns": [...], "red_flags": [...], "tasks": [...]}'
    - '{"type": "red_flag", "flag": {...}}'   — sent the instant a NEW flag is detected (drives the banner)
    - '{"type": "final", "run_id": "...", "result": {...}}'
    - '{"type": "error", "message": "..."}'

Whisper/barge-in are real control messages with real effects on THIS mocked call
(annotation / stopping playback), but do not dynamically alter future scripted
questions — this class specifically stays fully fixed/predictable, which is exactly
why it's kept alongside the newer DynamicIntakeCallSession as a reliable fallback
(?mode=fixed) that doesn't depend on the LLM to produce every line of dialogue.
"""
from __future__ import annotations

import asyncio
import logging

from fastapi import WebSocket

from ..config import Settings
from ..llm.client import LLMClient
from ..pipeline.base_intake_session import BaseIntakeCallSession
from ..pipeline.mock_intake_scripts import DEFAULT_SCRIPT_KEY, SCRIPTS, TURN_DELAY_SECONDS

logger = logging.getLogger(__name__)


class IntakeCallSession(BaseIntakeCallSession):
    """Manages one mocked, fixed-script AI outbound intake call."""

    mode = "fixed"

    def __init__(
        self,
        websocket: WebSocket,
        settings: Settings,
        llm_client: LLMClient,
        script_key: str = DEFAULT_SCRIPT_KEY,
    ) -> None:
        super().__init__(websocket, settings, llm_client)
        self.script_key = script_key if script_key in SCRIPTS else DEFAULT_SCRIPT_KEY
        self.script = SCRIPTS[self.script_key]

        logger.info(
            "IntakeCallSession created: run_id=%s script=%s", self.run_id, self.script_key
        )

    # ------------------------------------------------------------------
    # Main entry point
    # ------------------------------------------------------------------

    async def run(self) -> None:
        await self._send_json({
            "type": "call_status", "run_id": self.run_id,
            "status": "in_progress", "human_taken_over": False,
        })

        control_task = asyncio.create_task(self._receive_control())
        try:
            await self._pace_script()
        except Exception:
            logger.exception("[run=%s] Unexpected error while pacing script", self.run_id)
            await self._send_error("Intake call playback failed.")
        finally:
            self._stop_event.set()
            control_task.cancel()
            try:
                await control_task
            except (asyncio.CancelledError, Exception):
                pass

        await self._finalize()

    # ------------------------------------------------------------------
    # Scripted playback (paced, interruptible)
    # ------------------------------------------------------------------

    async def _pace_script(self) -> None:
        for turn in self.script:
            if self._stop_event.is_set():
                return

            await self._wait_while_held()
            if self._stop_event.is_set():
                return

            self.current_step = turn["step"]
            speaker = turn["speaker"]
            text = turn["text"]

            whisper_annotation = None
            if speaker == "Agent" and self._pending_whisper:
                whisper_annotation = self._pending_whisper
                self._pending_whisper = None

            turn_record = {"speaker": speaker, "text": text, "step": self.current_step}
            self.transcript_turns.append(turn_record)
            self._pending_chunk_turns.append(turn_record)

            await self._send_json({
                "type": "turn",
                "run_id": self.run_id,
                "step": self.current_step,
                "speaker": speaker,
                "text": text,
                "whisper_annotation": whisper_annotation,
            })

            if speaker == "Patient":
                await self._process_pending_chunk()

            await self._interruptible_sleep(TURN_DELAY_SECONDS)
