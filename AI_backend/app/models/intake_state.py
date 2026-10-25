"""
models/intake_state.py — Narrower MedicalState for AI outbound intake calls.

Reuses MedicalState's apply_patch()/to_canonical_json()/to_full_internal_state()
unchanged (apply_patch already ignores any patch key outside SECTION_KEYS), just
scoped to the 6 sections an intake call is allowed to fill.
"""
from __future__ import annotations

from .state import MedicalState


class IntakeState(MedicalState):
    SECTION_KEYS = [
        "chief_complaint",
        "history_of_present_illness",
        "past_history",
        "family_history",
        "current_medication",
        "drug_allergy",
    ]
