"""
models/intake.py — Pydantic models for the AI outbound intake-call output schema.

Scoped-down sibling of ClinicalExtraction (models/clinical.py): only the sections
an AI phone-intake agent is allowed to fill (chief complaint, history, current
medication, allergies, past/family history) — never exam/diagnosis/prescription,
which stay strictly doctor-only. Reuses the same Section/StrictBaseModel shape
so confidence scoring and the frontend's confidence-rendering pattern carry over
unchanged.
"""
from __future__ import annotations

from typing import List

from pydantic import Field

from .clinical import Section, StrictBaseModel


class RedFlag(StrictBaseModel):
    type: str = ""  # chest_pain | breathlessness | bleeding | stroke_signs | suicidal_intent
    severity: str = ""  # "low" | "medium" | "high" | "critical"
    evidence: str = ""
    suggested_task: str = ""


class IntakeTask(StrictBaseModel):
    text: str = ""
    source: str = ""  # "red_flag" | "low_confidence"
    section: str = ""  # which section this relates to, if any
    priority: str = ""  # "normal" | "high"


class IntakeExtraction(StrictBaseModel):
    """The final, canonical, frontend-ready AI intake-call extraction."""

    chief_complaint: Section = Field(default_factory=Section)
    history_of_present_illness: Section = Field(default_factory=Section)
    past_history: Section = Field(default_factory=Section)
    family_history: Section = Field(default_factory=Section)
    current_medication: Section = Field(default_factory=Section)
    drug_allergy: Section = Field(default_factory=Section)
    red_flags: List[RedFlag] = Field(default_factory=list)
    tasks: List[IntakeTask] = Field(default_factory=list)
