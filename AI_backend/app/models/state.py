"""
models/state.py — Internal medical state with provenance tracking.

MedicalState tracks each clinical field with:
  - canonical: the clean value that will be in the final JSON
  - evidence:  list of transcript quotes that support this value
  - confidence: 0.0–1.0
  - source_chunks: which audio chunk numbers contributed this data

The final output strips all metadata and returns only canonical values
as a ClinicalExtraction object.
"""
from __future__ import annotations

import copy
import json
from dataclasses import dataclass, field
from typing import Any


# ---------------------------------------------------------------------------
# Field-level provenance
# ---------------------------------------------------------------------------

@dataclass
class FieldEntry:
    canonical: Any = None
    evidence: list[str] = field(default_factory=list)
    confidence: float = 0.0
    source_chunks: list[int] = field(default_factory=list)

    def to_dict(self) -> dict:
        return {
            "canonical": self.canonical,
            "evidence": self.evidence,
            "confidence": self.confidence,
            "source_chunks": self.source_chunks,
        }


# ---------------------------------------------------------------------------
# MedicalState — one instance per WebSocket session
# ---------------------------------------------------------------------------

class MedicalState:
    """
    In-memory evolving state for one consultation session.

    Internally stores every section as a FieldEntry (with evidence/confidence).
    Exposes helpers to:
      - apply a delta patch from the LLM
      - export the canonical JSON (for sending to frontend)
      - export the full internal state (for logging)
    """

    SECTION_KEYS = [
        "chief_complaint",
        "history_of_present_illness",
        "past_history",
        "family_history",
        "drug_allergy",
        "clinical_examination",
        "diagnosis",
        "investigations",
        "prescription",
        "advice",
        "follow_up",
    ]

    def __init__(self) -> None:
        # Each section stores its value as a FieldEntry
        self._fields: dict[str, FieldEntry] = {
            key: FieldEntry() for key in self.SECTION_KEYS
        }
        self._chunk_count = 0

    # ------------------------------------------------------------------
    # Public API
    # ------------------------------------------------------------------

    def apply_patch(self, patch: dict[str, Any], chunk_index: int, evidence: str = "") -> None:
        """
        Apply an LLM delta patch to the state.

        patch: {section_key: new_canonical_value, ...}
        chunk_index: which chunk produced this patch
        evidence: the transcript text for this chunk (stored as evidence)
        """
        self._chunk_count = max(self._chunk_count, chunk_index)

        for key, value in patch.items():
            if key not in self._fields:
                continue  # ignore unknown keys

            entry = self._fields[key]
            if value is None or value == "" or value == [] or value == {}:
                continue  # LLM said nothing new for this field

            # If the new value is a correction (not empty), update canonical
            entry.canonical = value
            if evidence and evidence not in entry.evidence:
                entry.evidence.append(f"[chunk {chunk_index}] {evidence[:200]}")
            if chunk_index not in entry.source_chunks:
                entry.source_chunks.append(chunk_index)

            # Confidence reflects the LLM's own assessment of evidence quality for this
            # update (see CONFIDENCE SCORING RULES in prompts/system.py + delta.py) —
            # NOT how many chunks have touched the field. Fall back to a mild bump only
            # if the LLM omitted it (defensive, shouldn't normally happen).
            llm_confidence = value.get("confidence") if isinstance(value, dict) else None
            if isinstance(llm_confidence, (int, float)):
                entry.confidence = max(0.0, min(1.0, float(llm_confidence)))
            else:
                entry.confidence = min(1.0, entry.confidence + 0.2)

    def to_canonical_json(self) -> dict[str, Any]:
        """
        Return a dict containing only canonical values for all sections.
        This is what the LLM receives as 'current state' and what the
        frontend receives as the final result.

        Each section's confidence (0.0-1.0, tracked internally per FieldEntry)
        is injected fresh here so both interim and final payloads always carry
        the live value — never mutates entry.canonical itself.
        """
        out: dict[str, Any] = {}
        for key, entry in self._fields.items():
            base = entry.canonical if entry.canonical is not None else _empty_section_default(key)
            section = dict(base) if isinstance(base, dict) else base
            if isinstance(section, dict):
                section["confidence"] = entry.confidence
            out[key] = section
        return out

    def to_full_internal_state(self) -> dict[str, Any]:
        """
        Return the full internal state with provenance for logging.
        """
        return {key: entry.to_dict() for key, entry in self._fields.items()}

    def chunk_count(self) -> int:
        return self._chunk_count


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def _empty_section_default(section_key: str) -> Any:
    """Return the appropriate empty value for a section that has not been populated."""
    structured = {
        "clinical_examination": {
            "text": "", "items": [], "suggestions": [], "confidence": 0.0,
            "vitals": [], "general_examination": "", "systemic_examination": ""
        },
        "diagnosis": {
            "text": "", "items": [], "suggestions": [], "confidence": 0.0, "icd10": []
        },
        "prescription": {
            "text": "", "items": [], "suggestions": [], "confidence": 0.0, "medications": []
        },
        "follow_up": {
            "text": "", "items": [], "suggestions": [], "confidence": 0.0, "date_text": "", "instruction": ""
        },
    }
    if section_key in structured:
        return structured[section_key]
    return {"text": "", "items": [], "suggestions": [], "confidence": 0.0}
