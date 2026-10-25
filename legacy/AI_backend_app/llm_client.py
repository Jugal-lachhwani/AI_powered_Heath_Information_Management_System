from __future__ import annotations

import copy
import json
import logging
import re
from typing import Any

from pydantic import ValidationError
import openai

from .config import Settings, settings
from .models import ClinicalExtraction
from .prompts import build_messages


logger = logging.getLogger(__name__)


class LLMError(RuntimeError):
    """Raised when the LLM cannot produce valid clinical extraction JSON or the request fails."""


class LLMClient:
    def __init__(self, app_settings: Settings = settings) -> None:
        self.settings = app_settings
        self.client = openai.AsyncOpenAI(
            base_url=self.settings.base_url,
            api_key=self.settings.api_key,
            timeout=self.settings.request_timeout_seconds,
        )

    async def is_model_available(self) -> bool:
        # Since we use multiple providers (OpenAI, Grok, Nvidia, Ollama, Gemini), a unified model check
        # using the generic /models endpoint isn't perfectly reliable across all providers.
        # But we can try to fetch the models list and check if the configured model is available.
        # However, for simplicity and robustness across various OpenAI-compatible endpoints,
        # we will assume the model is available if the client initialized properly.
        # If the provider doesn't support models.list() easily, we'll just log and return True.
        
        try:
            models = await self.client.models.list()
            available_models = {model.id for model in models.data}
            detected = self.settings.llm_model in available_models
            
            if detected:
                logger.info("Model detected: %s", self.settings.llm_model)
            else:
                logger.warning(
                    "Model not detected: %s. Available models: %s",
                    self.settings.llm_model,
                    ", ".join(sorted(available_models)) or "none",
                )
            # Even if not found in list, we might still be able to use it (e.g., custom model names).
            return True
        except Exception as exc:
            logger.warning("Could not list models (this is normal for some providers): %s", exc)
            return True

    async def extract(self, transcript: str) -> tuple[ClinicalExtraction, dict]:
        import time
        from .utils import estimate_tokens
        
        logger.info("Stage: Starting extraction")
        logger.info(
            "Starting clinical extraction with provider=%s model=%s transcript_chars=%s",
            self.settings.llm_provider,
            self.settings.llm_model,
            len(transcript),
        )
        
        start_time = time.time()
        ttft = 0.0
        content_chunks = []
        
        try:
            logger.info("Stage: Sending request to LLM")
            stream = await self.client.chat.completions.create(
                model=self.settings.llm_model,
                messages=build_messages(transcript), # type: ignore
                temperature=self.settings.temperature,
                # Force JSON output if the provider supports it.
                # OpenAI supports `response_format={"type": "json_object"}`.
                # If a provider doesn't support it, this might throw an error, 
                # but most OpenAI-compatible endpoints do.
                response_format={"type": "json_object"} if self.settings.llm_provider.lower() not in ("ollama", "groq") else None,
                stream=True,
            )
            
            logger.info("Stage: Waiting for first token")
            async for chunk in stream:
                if chunk.choices and chunk.choices[0].delta.content:
                    if ttft == 0.0:
                        ttft = time.time() - start_time
                        logger.info("Stage: Receiving first token")
                    content_chunks.append(chunk.choices[0].delta.content)
            
            logger.info("Stage: Stream completed")
        except Exception as exc:
            logger.exception("LLM API request failed.")
            raise LLMError(f"LLM request failed: {exc}") from exc
            
        end_time = time.time()
        tt = end_time - start_time
        content = "".join(content_chunks)
        
        if not content:
             raise LLMError("LLM returned an empty response.")
             
        total_tokens = estimate_tokens(content)
        tpt = (tt - ttft) / total_tokens if total_tokens > 0 else 0.0
        
        metrics = {
            "TTFT": round(ttft, 4),
            "TPT": round(tpt, 4),
            "TT": round(tt, 4),
            "Gen_Time": round(tt - ttft, 4)
        }
             
        logger.info("Stage: Parsing JSON")
             
        parsed_json = _parse_json_object(content)
        normalized_json = _normalize_extraction_json(parsed_json)

        try:
            extraction = _validate_extraction(normalized_json)
            logger.info("Clinical extraction completed successfully.")
            if self.settings.log_ai_json:
                logger.info("Clinical extraction JSON:\n%s", _model_to_json(extraction))
            return extraction, metrics
        except ValidationError as exc:
            logger.exception("LLM returned invalid clinical extraction JSON.")
            raise LLMError(
                "LLM returned JSON that does not match the expected schema."
            ) from exc


def _parse_json_object(content: str) -> dict[str, Any]:
    cleaned = content.strip()
    cleaned = re.sub(r"^```(?:json)?\s*", "", cleaned, flags=re.IGNORECASE)
    cleaned = re.sub(r"\s*```$", "", cleaned)

    try:
        parsed = json.loads(cleaned)
    except json.JSONDecodeError:
        start = cleaned.find("{")
        end = cleaned.rfind("}")
        if start == -1 or end == -1 or start >= end:
            raise LLMError("LLM response did not contain valid JSON.") from None

        try:
            parsed = json.loads(cleaned[start : end + 1])
        except json.JSONDecodeError as exc:
            raise LLMError("LLM response did not contain valid JSON.") from exc

    if not isinstance(parsed, dict):
        raise LLMError("LLM JSON response must be an object.")

    return parsed


def _normalize_extraction_json(data: dict[str, Any]) -> dict[str, Any]:
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
        if isinstance(section, dict):
            items = [
                item
                for item in (_stringify_item(item) for item in section.get("items", []))
                if item
            ]
            if not items:
                items = _derive_items(section_name, section.get("text", ""))
            section["items"] = items

    prescription = normalized.get("prescription")
    if isinstance(prescription, dict):
        medications = prescription.get("medications")
        if not medications:
            original_prescription = data.get("prescription")
            original_items = (
                original_prescription.get("items", [])
                if isinstance(original_prescription, dict)
                else []
            )
            medication_items = [
                item
                for item in original_items
                if isinstance(item, dict) and item.get("medicine")
            ]
            if medication_items:
                prescription["medications"] = medication_items

    examination = normalized.get("clinical_examination")
    if isinstance(examination, dict):
        vitals = examination.get("vitals")
        if not vitals:
            raw_exam_text = " ".join(
                value
                for value in [
                    examination.get("text"),
                    examination.get("general_examination"),
                    " ".join(examination.get("items", [])),
                ]
                if isinstance(value, str)
            )
            examination["vitals"] = _extract_vitals_from_text(raw_exam_text)
        examination["vitals"] = [
            vital
            for vital in examination.get("vitals", [])
            if isinstance(vital, dict) and (vital.get("label") or vital.get("value"))
        ]

    diagnosis = normalized.get("diagnosis")
    if isinstance(diagnosis, dict):
        diagnosis["icd10"] = [
            code
            for code in diagnosis.get("icd10", [])
            if isinstance(code, dict) and (code.get("code") or code.get("description"))
        ]

    if isinstance(prescription, dict):
        prescription["medications"] = [
            medication
            for medication in prescription.get("medications", [])
            if isinstance(medication, dict)
            and any(medication.get(field) for field in ["medicine", "dose", "frequency", "duration"])
        ]

    return normalized


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
        return ", ".join(str(value) for value in item.values() if value).strip()
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
        ("Temp", "°F", r"\b(?:temperature|temp)\s*[:\-]?\s*(\d{2,3}(?:\.\d+)?)\s*(?:°?\s*f|fahrenheit)?"),
        ("SpO2", "%", r"\b(?:SpO2|oxygen saturation)\s*[:\-]?\s*(\d{2,3})\s*%?"),
        ("Weight", "kg", r"\bweight\s*[:\-]?\s*(\d{1,3}(?:\.\d+)?)\s*(?:kg)?"),
    ]

    for label, unit, pattern in patterns:
        match = re.search(pattern, text, flags=re.IGNORECASE)
        if match:
            vitals.append(
                {
                    "label": label,
                    "value": match.group(1).replace(" ", ""),
                    "unit": unit,
                }
            )

    return vitals


def _validate_extraction(data: dict[str, Any]) -> ClinicalExtraction:
    if hasattr(ClinicalExtraction, "model_validate"):
        return ClinicalExtraction.model_validate(data)  # type: ignore[attr-defined]
    return ClinicalExtraction.parse_obj(data)


def _model_to_json(model: ClinicalExtraction) -> str:
    if hasattr(model, "model_dump_json"):
        return model.model_dump_json(indent=2)  # type: ignore[attr-defined]
    return model.json(indent=2)
