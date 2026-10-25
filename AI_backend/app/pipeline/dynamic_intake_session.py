"""
pipeline/dynamic_intake_session.py — Dynamic AI outbound intake call (mocked telephony).

Unlike IntakeCallSession's fixed two-party script, here BOTH sides of the
conversation are generated live: the agent's line comes from an LLM given the
current script step's *instruction* (doctor-authored in 1e, see
app/prompts/agent_turn.py), and the patient's reply comes from a second LLM
call role-playing a patient consistent with a short scenario description (see
app/prompts/patient_turn.py). Extraction and red-flag checking after each
Patient turn are unchanged — see base_intake_session.py for everything shared.

AI_backend deliberately never touches hims.sqlite (PHP owns all persistence) —
so the doctor-authored script can't be loaded from the DB directly here. Instead
the client sends it as the FIRST control frame right after connecting:

    {"type": "init", "steps": [{"step_key","title","prompt_text","fills_field"}, ...],
     "scenario_text": "...", "patient_name": "..."}

run() waits (bounded, 10s) for this frame before starting playback.

WebSocket protocol: identical to IntakeCallSession's (see its docstring) plus
the "init" frame above, which must be sent before anything else.
"""
from __future__ import annotations

import asyncio
import logging

from fastapi import WebSocket

from ..config import Settings
from ..llm.client import LLMClient, LLMError
from ..pipeline.base_intake_session import BaseIntakeCallSession

logger = logging.getLogger(__name__)

# Dynamic mode already has natural pacing from LLM latency (two calls per step);
# a shorter extra sleep than fixed mode's TURN_DELAY_SECONDS is enough to keep
# the monitor screen feeling like a real call rather than instant.
TURN_PACING_SECONDS = 1.0
INIT_TIMEOUT_SECONDS = 10.0


class DynamicIntakeCallSession(BaseIntakeCallSession):
    """Manages one mocked AI outbound intake call driven by a doctor-authored script."""

    mode = "dynamic"

    def __init__(self, websocket: WebSocket, settings: Settings, llm_client: LLMClient) -> None:
        super().__init__(websocket, settings, llm_client)
        self._init_event = asyncio.Event()
        self.steps: list[dict] = []
        self.scenario_text = ""
        self.patient_name = ""

        logger.info("DynamicIntakeCallSession created: run_id=%s", self.run_id)

    def _handle_extra_control(self, ctype: str | None, control: dict) -> bool:
        if ctype != "init":
            return False
        steps = control.get("steps")
        if isinstance(steps, list):
            self.steps = steps
        self.scenario_text = str(control.get("scenario_text", ""))[:2000]
        self.patient_name = str(control.get("patient_name", ""))[:200]
        self._init_event.set()
        return True

    # ------------------------------------------------------------------
    # Main entry point
    # ------------------------------------------------------------------

    async def run(self) -> None:
        await self._send_json({
            "type": "call_status", "run_id": self.run_id,
            "status": "waiting_for_script", "human_taken_over": False,
        })

        control_task = asyncio.create_task(self._receive_control())

        try:
            await asyncio.wait_for(self._init_event.wait(), timeout=INIT_TIMEOUT_SECONDS)
        except asyncio.TimeoutError:
            logger.warning("[run=%s] No init frame received within %.0fs", self.run_id, INIT_TIMEOUT_SECONDS)
            await self._send_error("No script received from the client — call cannot start.")
            control_task.cancel()
            try:
                await control_task
            except (asyncio.CancelledError, Exception):
                pass
            return

        if not self.steps:
            await self._send_error("Script has no steps.")
            control_task.cancel()
            try:
                await control_task
            except (asyncio.CancelledError, Exception):
                pass
            return

        await self._send_json({
            "type": "call_status", "run_id": self.run_id,
            "status": "in_progress", "human_taken_over": False,
        })

        try:
            await self._pace_script()
        except Exception:
            logger.exception("[run=%s] Unexpected error while pacing dynamic script", self.run_id)
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
    # LLM-generated playback (paced, interruptible)
    # ------------------------------------------------------------------

    async def _pace_script(self) -> None:
        for step in self.steps:
            if self._stop_event.is_set():
                return
            await self._wait_while_held()
            if self._stop_event.is_set():
                return

            self.current_step = step.get("step_key", "")
            instruction = str(step.get("prompt_text", "")).strip()
            if not instruction:
                continue

            try:
                agent_text, _metrics = await self.processor.generate_agent_turn(
                    instruction, self.transcript_turns, self.patient_name
                )
                self._record_usage("agent_turn", _metrics)
            except LLMError as exc:
                self._record_failed_usage("agent_turn")
                logger.warning(
                    "[run=%s] Agent turn generation failed for step %s: %s",
                    self.run_id, self.current_step, exc,
                )
                agent_text = ""
            if not agent_text:
                # Graceful degrade: skip this step rather than send an empty/broken turn.
                await self._interruptible_sleep(TURN_PACING_SECONDS)
                continue

            whisper_annotation = None
            if self._pending_whisper:
                whisper_annotation = self._pending_whisper
                self._pending_whisper = None

            agent_record = {"speaker": "Agent", "text": agent_text, "step": self.current_step}
            self.transcript_turns.append(agent_record)
            await self._send_json({
                "type": "turn",
                "run_id": self.run_id,
                "step": self.current_step,
                "speaker": "Agent",
                "text": agent_text,
                "whisper_annotation": whisper_annotation,
                "usage": self._usage_snapshot(),
            })

            await self._interruptible_sleep(TURN_PACING_SECONDS)
            if self._stop_event.is_set():
                return
            await self._wait_while_held()
            if self._stop_event.is_set():
                return

            try:
                patient_text, _metrics = await self.processor.generate_patient_turn(
                    self.scenario_text, self.transcript_turns, agent_text
                )
                self._record_usage("patient_turn", _metrics)
            except LLMError as exc:
                self._record_failed_usage("patient_turn")
                logger.warning(
                    "[run=%s] Patient turn generation failed for step %s: %s",
                    self.run_id, self.current_step, exc,
                )
                patient_text = ""
            if not patient_text:
                await self._interruptible_sleep(TURN_PACING_SECONDS)
                continue

            patient_record = {"speaker": "Patient", "text": patient_text, "step": self.current_step}
            self.transcript_turns.append(patient_record)
            self._pending_chunk_turns.extend([agent_record, patient_record])
            await self._send_json({
                "type": "turn",
                "run_id": self.run_id,
                "step": self.current_step,
                "speaker": "Patient",
                "text": patient_text,
                "whisper_annotation": None,
                "usage": self._usage_snapshot(),
            })

            await self._process_pending_chunk()
            await self._interruptible_sleep(TURN_PACING_SECONDS)
