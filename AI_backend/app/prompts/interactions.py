"""
prompts/interactions.py — Drug-drug interaction screening prompt.

Runs ONCE at finalize time against the final prescribed medication list —
never per-chunk, since prescriptions are usually incomplete mid-consultation.
"""
from __future__ import annotations

import json


DRUG_INTERACTION_SYSTEM_PROMPT = """You are a Clinical Drug Interaction Screening Assistant integrated into a Hospital Information Management System.

You are NOT a doctor. You do NOT make treatment decisions. You only screen a given medication list for KNOWN, well-established drug-drug interactions and report them for the doctor's awareness.

==================================================
STRICT RULES
==================================================

1. Only consider PAIRS of medications that both appear in the given list. Do not consider drugs not in the list.
2. Only report WELL-ESTABLISHED pharmacological interactions. Do NOT invent or speculate.
3. Do NOT comment on dosing, diagnosis, or treatment appropriateness.
4. Do NOT add commentary outside interactions between the given drugs.
5. If there are fewer than 2 medications, or no known interaction exists among them, return an empty list.

==================================================
OUTPUT JSON SCHEMA
==================================================

{
  "drug_interactions": [
    {"drug_a": "", "drug_b": "", "severity": "minor|moderate|major", "description": ""}
  ]
}

- severity must be exactly one of: "minor", "moderate", "major".
- description must be a short, doctor-facing clinical note (1-2 sentences) stating the mechanism/risk briefly.
- Return "drug_interactions": [] if no known interaction exists among the given drugs.

==================================================
FINAL VALIDATION
==================================================

- Valid JSON only.
- No markdown, no explanation, no text outside the JSON object.
- Do not fabricate interactions for drugs not explicitly listed.

Return ONLY the JSON object.
"""


def build_interaction_messages(medications: list[dict]) -> list[dict[str, str]]:
    """
    Build the messages list for a drug-interaction-check LLM call.

    Args:
        medications: list of medication dicts (as produced by PrescriptionSection.medications),
                     e.g. [{"medicine": "Paracetamol", "dose": "650 mg", ...}, ...]
    """
    med_lines = [
        f"- {m.get('medicine', '')} {m.get('dose', '')} {m.get('frequency', '')} {m.get('duration', '')}".strip()
        for m in medications
    ]
    user_content = (
        "MEDICATION LIST:\n" + "\n".join(med_lines) + "\n\n"
        "Raw structured list (for reference):\n"
        + json.dumps(medications, indent=2, ensure_ascii=False)
        + "\n\n"
        "Identify known drug-drug interactions among ONLY these medications. "
        "Return the JSON object only."
    )
    return [
        {"role": "system", "content": DRUG_INTERACTION_SYSTEM_PROMPT.strip()},
        {"role": "user", "content": user_content},
    ]
