"""
llm/parser.py — JSON parsing and normalization helpers.

Extracted from the old llm_client.py so the LLM client stays clean.
"""
from __future__ import annotations

import copy
import json
import re
from typing import Any


# ---------------------------------------------------------------------------
# JSON extraction
# ---------------------------------------------------------------------------

class ParseError(RuntimeError):
    """Raised when the LLM response cannot be parsed into valid JSON."""


def parse_json_object(content: str) -> dict[str, Any]:
    """
    Extract and parse a JSON object from raw LLM output.
    Handles markdown code fences and leading/trailing junk.
    """
    cleaned = content.strip()
    # Strip markdown fences
    cleaned = re.sub(r"^```(?:json)?\s*", "", cleaned, flags=re.IGNORECASE)
    cleaned = re.sub(r"\s*```$", "", cleaned)

    try:
        parsed = json.loads(cleaned)
    except json.JSONDecodeError:
        # Try to find the outermost JSON object
        start = cleaned.find("{")
        end = cleaned.rfind("}")
        if start == -1 or end == -1 or start >= end:
            raise ParseError("LLM response did not contain valid JSON.") from None
        try:
            parsed = json.loads(cleaned[start: end + 1])
        except json.JSONDecodeError as exc:
            raise ParseError("LLM response did not contain valid JSON.") from exc

    if not isinstance(parsed, dict):
        raise ParseError("LLM JSON response must be an object.")

    return parsed


# ---------------------------------------------------------------------------
# Normalization — ensures the extraction conforms to expected structure
# ---------------------------------------------------------------------------

def normalize_extraction(data: dict[str, Any]) -> dict[str, Any]:
    """
    Post-process LLM extraction JSON:
    - Ensure items are string arrays
    - Derive items from text if missing
    - Filter empty vitals / medications / icd10 entries
    """
    normalized = copy.deepcopy(data)

    section_names = [
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

    for section_name in section_names:
        section = normalized.get(section_name)
        if not isinstance(section, dict):
            continue

        items = [
            item
            for item in (_stringify_item(i) for i in section.get("items", []))
            if item
        ]
        if not items:
            items = _derive_items(section_name, section.get("text", ""))
        section["items"] = items

    # Prescription medications fallback
    prescription = normalized.get("prescription")
    if isinstance(prescription, dict):
        medications = prescription.get("medications")
        if not medications:
            original_items = (
                data.get("prescription", {}).get("items", [])
                if isinstance(data.get("prescription"), dict)
                else []
            )
            medication_items = [
                item for item in original_items
                if isinstance(item, dict) and item.get("medicine")
            ]
            if medication_items:
                prescription["medications"] = medication_items

        # Filter empty medication rows
        prescription["medications"] = [
            med for med in prescription.get("medications", [])
            if isinstance(med, dict)
            and any(med.get(f) for f in ["medicine", "dose", "frequency", "duration"])
        ]

    # Clinical examination — extract vitals from text if missing
    examination = normalized.get("clinical_examination")
    if isinstance(examination, dict):
        vitals = examination.get("vitals")
        if not vitals:
            raw_text = " ".join(
                v for v in [
                    examination.get("text"),
                    examination.get("general_examination"),
                    " ".join(examination.get("items", [])),
                ]
                if isinstance(v, str)
            )
            examination["vitals"] = _extract_vitals_from_text(raw_text)
        examination["vitals"] = [
            v for v in examination.get("vitals", [])
            if isinstance(v, dict) and (v.get("label") or v.get("value"))
        ]

    # Diagnosis — filter empty ICD10 entries
    diagnosis = normalized.get("diagnosis")
    if isinstance(diagnosis, dict):
        diagnosis["icd10"] = [
            code for code in diagnosis.get("icd10", [])
            if isinstance(code, dict) and (code.get("code") or code.get("description"))
        ]

    return normalized


# ---------------------------------------------------------------------------
# Private helpers
# ---------------------------------------------------------------------------

def _stringify_item(item: Any) -> str:
    if item is None:
        return ""
    if isinstance(item, str):
        return item.strip()
    if isinstance(item, dict):
        label = item.get("label") or item.get("name") or item.get("medicine") or ""
        value = item.get("value") or item.get("text") or ""
        if label and value:
            return f"{label}: {value}".strip()
        if label:
            return str(label).strip()
        if value:
            return str(value).strip()
        return ", ".join(str(v) for v in item.values() if v).strip()
    return str(item).strip()


def _derive_items(section_name: str, text: Any) -> list[str]:
    if not isinstance(text, str) or not text.strip():
        return []

    list_sections = {
        "chief_complaint",
        "past_history",
        "family_history",
        "drug_allergy",
        "investigations",
        "advice",
    }
    if section_name not in list_sections:
        return []

    cleaned = re.sub(
        r"\b(advised|ordered|reviewed|documented|prescribed)\b",
        "",
        text,
        flags=re.IGNORECASE,
    )
    cleaned = re.sub(r"\s+", " ", cleaned).strip(" .")

    if section_name == "chief_complaint":
        cleaned = re.sub(r"\s+with\s+", "\n", cleaned, flags=re.IGNORECASE)

    parts = re.split(r"\r?\n|;|,|\s+\band\b\s+", cleaned, flags=re.IGNORECASE)
    return [part.strip(" .") for part in parts if part.strip(" .")]


def _extract_vitals_from_text(text: str) -> list[dict[str, str]]:
    vitals: list[dict[str, str]] = []

    patterns = [
        ("BP", "mmHg", r"\b(?:BP|blood pressure)\s*[:\-]?\s*(\d{2,3}\s*/\s*\d{2,3})"),
        ("Pulse", "/min", r"\b(?:pulse|heart rate)\s*[:\-]?\s*(\d{2,3})"),
        ("Temp", "°F", r"\b(?:temperature|temp)\s*[:\-]?\s*(\d{2,3}(?:\.\d+)?)"),
        ("SpO2", "%", r"\b(?:SpO2|oxygen saturation)\s*[:\-]?\s*(\d{2,3})\s*%?"),
        ("Weight", "kg", r"\bweight\s*[:\-]?\s*(\d{1,3}(?:\.\d+)?)\s*(?:kg)?"),
    ]

    for label, unit, pattern in patterns:
        match = re.search(pattern, text, flags=re.IGNORECASE)
        if match:
            vitals.append({
                "label": label,
                "value": match.group(1).replace(" ", ""),
                "unit": unit,
            })

    return vitals
