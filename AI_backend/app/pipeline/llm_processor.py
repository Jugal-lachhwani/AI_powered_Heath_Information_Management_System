"""
pipeline/llm_processor.py — LLM delta update processor.

For each audio chunk, this module:
1. Takes the current canonical medical state JSON + new chunk transcript
2. Calls the LLM with the delta prompt
3. Returns the patch dict to apply to MedicalState

For the very first chunk (or if state is empty), it can optionally do a
full extraction instead of a delta — controlled by the caller.
"""
from __future__ import annotations

import json
import logging
from typing import Any

from ..llm.client import LLMClient, LLMError
from ..models.clinical import ClinicalExtraction
from ..prompts.delta import build_delta_messages
from ..prompts.system import get_system_prompt
from ..prompts.interactions import build_interaction_messages
from ..prompts.transcript import build_transcript_messages
from ..prompts.intake_system import get_intake_system_prompt
from ..prompts.red_flags import build_red_flag_messages
from ..prompts.agent_turn import build_agent_turn_messages
from ..prompts.patient_turn import build_patient_turn_messages

logger = logging.getLogger(__name__)


class LLMProcessor:
    """
    Wraps LLMClient with the medical extraction logic.
    One instance per session (or shared — it's stateless).
    """

    def __init__(self, llm_client: LLMClient, prompt_version: str = "v1") -> None:
        self._client = llm_client
        self._prompt_version = prompt_version

    async def process_chunk(
        self,
        chunk_index: int,
        transcript: str,
        current_state: dict[str, Any],
    ) -> tuple[dict[str, Any], dict[str, float]]:
        """
        Process a single chunk and return (patch_dict, metrics).

        If the state is empty (first chunk), uses the full system prompt.
        If the state has content, uses the delta prompt.

        Args:
            chunk_index:   1-based chunk number.
            transcript:    Raw transcript text from STT for this chunk.
            current_state: Current canonical medical state dict.

        Returns:
            patch: dict of changed keys (may be empty if no new info).
            metrics: timing metrics dict.
        """
        if not transcript.strip():
            logger.warning("Chunk %d: empty transcript, skipping LLM call", chunk_index)
            return {}, {}

        is_first_chunk = _is_empty_state(current_state)

        if is_first_chunk:
            logger.info("Chunk %d: empty state — running full extraction", chunk_index)
            patch, metrics = await self._full_extraction(chunk_index, transcript)
        else:
            logger.info("Chunk %d: running delta update", chunk_index)
            patch, metrics = await self._delta_update(chunk_index, transcript, current_state)

        return patch, metrics

    async def check_drug_interactions(
        self, medications: list[dict[str, Any]]
    ) -> tuple[list[dict[str, Any]], dict[str, float]]:
        """
        Check for known drug-drug interactions among the final prescribed medications.

        Runs ONCE at finalize time (never per-chunk — prescriptions are usually
        incomplete mid-consultation). Short-circuits with no LLM call if there
        are fewer than 2 medications, since no interaction is possible.
        """
        if len(medications) < 2:
            return [], {}

        messages = build_interaction_messages(medications)

        try:
            interactions, metrics = await self._client.extract_interactions(
                messages, label="final_drug_interactions"
            )
            return interactions, metrics
        except LLMError as exc:
            logger.error("Drug interaction check failed: %s", exc)
            return [], {}

    async def process_intake_chunk(
        self,
        chunk_index: int,
        transcript: str,
        current_state: dict[str, Any],
    ) -> tuple[dict[str, Any], dict[str, float]]:
        """
        AI outbound intake-call sibling of process_chunk(), scoped to the 6
        intake-only sections (see models/intake.py, models/intake_state.py).

        First chunk uses the intake-scoped full-extraction prompt; subsequent
        chunks reuse _delta_update/build_delta_messages UNCHANGED — the delta
        prompt only ever patches whatever keys are present in current_state,
        so it works correctly against IntakeState's narrower shape with no
        modification needed.
        """
        if not transcript.strip():
            return {}, {}

        if _is_empty_state(current_state):
            logger.info("Intake chunk %d: empty state — running full extraction", chunk_index)
            patch, metrics = await self._intake_full_extraction(chunk_index, transcript)
        else:
            logger.info("Intake chunk %d: running delta update", chunk_index)
            patch, metrics = await self._delta_update(chunk_index, transcript, current_state)

        return patch, metrics

    async def check_red_flags(
        self, transcript_turns: list[dict[str, Any]]
    ) -> tuple[list[dict[str, Any]], dict[str, float]]:
        """
        Screen the intake call transcript so far for clinically urgent red flags.

        Unlike check_drug_interactions (which only runs once at finalize), this
        must run every chunk during a live intake call — the monitor screen needs
        to raise the red-flag banner the moment one appears, not just in a
        post-call summary.
        """
        if not transcript_turns:
            return [], {}

        messages = build_red_flag_messages(transcript_turns)

        try:
            flags, metrics = await self._client.extract_red_flags(
                messages, label="intake_red_flags"
            )
            return flags, metrics
        except LLMError as exc:
            logger.error("Red flag check failed: %s", exc)
            return [], {}

    async def generate_agent_turn(
        self,
        step_instruction: str,
        transcript_so_far: list[dict[str, Any]],
        patient_name: str = "",
    ) -> tuple[str, dict[str, float]]:
        """
        Dynamic-mode only (DynamicIntakeCallSession): the AI agent's actual spoken
        line for the current script step, given the step's instruction. Raises
        LLMError on failure — the caller (dynamic session) decides how to degrade,
        since there's no sensible empty-string fallback for "what the agent says."
        """
        messages = build_agent_turn_messages(step_instruction, transcript_so_far, patient_name)
        return await self._client.generate_agent_turn(messages)

    async def generate_patient_turn(
        self,
        scenario_text: str,
        transcript_so_far: list[dict[str, Any]],
        agent_question: str,
    ) -> tuple[str, dict[str, float]]:
        """Dynamic-mode only: the LLM-simulated patient's reply. Raises LLMError on failure."""
        messages = build_patient_turn_messages(scenario_text, transcript_so_far, agent_question)
        return await self._client.generate_patient_turn(messages)

    async def structure_transcript_chunk(
        self, existing_turns: list[dict[str, Any]], new_chunk_transcript: str
    ) -> tuple[list[dict[str, Any]], dict[str, float]]:
        """
        Structure this chunk's raw transcript into new Doctor/Patient turns,
        given the turns already extracted so far (so overlapping chunk audio
        isn't shown twice). Runs every chunk, independent of clinical extraction.
        """
        if not new_chunk_transcript.strip():
            return [], {}

        messages = build_transcript_messages(existing_turns, new_chunk_transcript)

        try:
            turns, metrics = await self._client.extract_transcript_turns(
                messages, label="transcript_turns"
            )
            return turns, metrics
        except LLMError as exc:
            logger.error("Transcript structuring failed: %s", exc)
            return [], {}

    # ------------------------------------------------------------------
    # Private
    # ------------------------------------------------------------------

    async def _full_extraction(
        self, chunk_index: int, transcript: str
    ) -> tuple[dict[str, Any], dict[str, float]]:
        """
        Full extraction for the first chunk.
        Returns the full state as the 'patch' (since all keys are new).
        """
        from ..prompts.system import get_system_prompt

        messages = [
            {"role": "system", "content": get_system_prompt(self._prompt_version)},
            {
                "role": "user",
                "content": (
                    "Doctor consultation transcript begins:\n"
                    f"{transcript.strip()}\n"
                    "Doctor consultation transcript ends.\n\n"
                    "Extract the structured clinical JSON from this transcript only. "
                    "Return the JSON object only."
                ),
            },
        ]

        try:
            extraction, metrics = await self._client.extract_full(
                messages, label=f"chunk_{chunk_index:02d}_full"
            )
            patch = extraction.model_dump()
            return patch, metrics
        except LLMError as exc:
            logger.error("Chunk %d full extraction failed: %s", chunk_index, exc)
            return {}, {}

    async def _intake_full_extraction(
        self, chunk_index: int, transcript: str
    ) -> tuple[dict[str, Any], dict[str, float]]:
        """
        Full extraction for the first chunk of an AI outbound intake call.
        Mirrors _full_extraction() but against the intake-scoped prompt/schema.
        """
        messages = [
            {"role": "system", "content": get_intake_system_prompt()},
            {
                "role": "user",
                "content": (
                    "AI intake call transcript begins:\n"
                    f"{transcript.strip()}\n"
                    "AI intake call transcript ends.\n\n"
                    "Extract the structured intake JSON from this transcript only. "
                    "Return the JSON object only."
                ),
            },
        ]

        try:
            extraction, metrics = await self._client.extract_intake_full(
                messages, label=f"intake_chunk_{chunk_index:02d}_full"
            )
            patch = extraction.model_dump()
            return patch, metrics
        except LLMError as exc:
            logger.error("Intake chunk %d full extraction failed: %s", chunk_index, exc)
            return {}, {}

    async def _delta_update(
        self,
        chunk_index: int,
        transcript: str,
        current_state: dict[str, Any],
    ) -> tuple[dict[str, Any], dict[str, float]]:
        """
        Delta update for subsequent chunks.
        Returns only the changed keys as the patch.
        """
        current_state_json = json.dumps(current_state, indent=2, ensure_ascii=False)

        messages = build_delta_messages(
            current_state_json=current_state_json,
            chunk_transcript=transcript,
            chunk_index=chunk_index,
        )

        try:
            patch, metrics = await self._client.extract_delta(
                messages, label=f"chunk_{chunk_index:02d}_delta"
            )
            return patch, metrics
        except LLMError as exc:
            logger.error("Chunk %d delta update failed: %s", chunk_index, exc)
            return {}, {}


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def _is_empty_state(state: dict[str, Any]) -> bool:
    """
    Return True if the state has no meaningful content yet.
    Checks if all section texts are empty or missing.
    """
    if not state:
        return True

    for key, value in state.items():
        if isinstance(value, dict):
            text = value.get("text", "")
            items = value.get("items", [])
            if text or items:
                return False
        elif isinstance(value, str) and value:
            return False

    return True