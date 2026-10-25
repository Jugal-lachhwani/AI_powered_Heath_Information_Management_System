"""
llm/client.py — Async LLM client with provider resolution.

Supports: openai | grok | nvidia | gemini | groq | ollama
All provider URL and API key logic lives HERE, not in config.py.
"""
from __future__ import annotations

import logging
import time
from typing import Any

import openai
from pydantic import ValidationError

from ..config import Settings
from ..models.clinical import ClinicalExtraction
from ..models.intake import IntakeExtraction
from ..utils.tokens import estimate_tokens
from .parser import parse_json_object, normalize_extraction, ParseError


logger = logging.getLogger(__name__)


class LLMError(RuntimeError):
    """Raised when the LLM cannot return a usable response."""


# ---------------------------------------------------------------------------
# Provider resolution — base URL and API key from settings
# ---------------------------------------------------------------------------

_PROVIDER_BASE_URLS: dict[str, str | None] = {
    "openai": None,
    "grok": "https://api.x.ai/v1",
    "nvidia": "https://integrate.api.nvidia.com/v1",
    "gemini": "https://generativelanguage.googleapis.com/v1beta/openai/",
    "groq": "https://api.groq.com/openai/v1",
}


def _resolve_base_url(settings: Settings) -> str | None:
    provider = settings.llm_provider.lower()
    if provider == "ollama":
        return settings.ollama_base_url
    return _PROVIDER_BASE_URLS.get(provider)


def _resolve_api_key(settings: Settings) -> str:
    provider = settings.llm_provider.lower()
    key_map = {
        "openai": settings.openai_api_key,
        "grok": settings.grok_api_key,
        "nvidia": settings.nvidia_api_key,
        "gemini": settings.gemini_api_key,
        "groq": settings.groq_api_key,
        "ollama": "ollama",
    }
    return key_map.get(provider, "sk-dummy") or "sk-dummy"


def _supports_json_mode(provider: str) -> bool:
    """Providers that support response_format={"type": "json_object"}."""
    return provider.lower() not in ("ollama", "groq")


# ---------------------------------------------------------------------------
# LLM Client
# ---------------------------------------------------------------------------

class LLMClient:
    """
    Async OpenAI-compatible LLM client.
    One instance shared across all sessions (stateless).
    """

    def __init__(self, settings: Settings) -> None:
        self.settings = settings
        self._client = openai.AsyncOpenAI(
            base_url=_resolve_base_url(settings),
            api_key=_resolve_api_key(settings),
            timeout=settings.llm_timeout_seconds,
        )
        logger.info(
            "LLMClient initialized: provider=%s model=%s",
            settings.llm_provider,
            settings.llm_model,
        )

    async def is_model_available(self) -> bool:
        """Best-effort model availability check. Returns True on error (safe default)."""
        try:
            models = await self._client.models.list()
            available = {m.id for m in models.data}
            if self.settings.llm_model in available:
                logger.info("Model confirmed: %s", self.settings.llm_model)
            else:
                logger.warning(
                    "Model not in list: %s. Available: %s",
                    self.settings.llm_model,
                    ", ".join(sorted(available)) or "none",
                )
        except Exception as exc:
            logger.warning("Could not list models (normal for some providers): %s", exc)
        return True

    async def call(
        self,
        messages: list[dict[str, str]],
        *,
        label: str = "llm_call",
        json_mode: bool = True,
    ) -> tuple[str, dict[str, float]]:
        """
        Make a streaming LLM call and return the full response text + timing metrics.

        json_mode: True (default, preserves every existing caller's behavior) forces
            the provider's JSON-object response mode for providers that support it.
            Set False for plain conversational text generation (e.g. agent/patient
            turn phrasing) — forcing JSON mode there would make the provider refuse
            to return a normal spoken-line string.

        Returns:
            (response_text, metrics_dict)
        """
        start = time.monotonic()
        ttft = 0.0
        chunks: list[str] = []

        provider = self.settings.llm_provider.lower()
        kwargs: dict[str, Any] = {
            "model": self.settings.llm_model,
            "messages": messages,  # type: ignore[arg-type]
            "temperature": self.settings.llm_temperature,
            "stream": True,
        }
        if json_mode and _supports_json_mode(provider):
            kwargs["response_format"] = {"type": "json_object"}

        try:
            logger.debug("[%s] Sending request to LLM", label)
            stream = await self._client.chat.completions.create(**kwargs)

            async for chunk in stream:
                if chunk.choices and chunk.choices[0].delta.content:
                    if ttft == 0.0:
                        ttft = time.monotonic() - start
                        logger.debug("[%s] First token received (TTFT=%.2fs)", label, ttft)
                    chunks.append(chunk.choices[0].delta.content)

        except Exception as exc:
            logger.exception("[%s] LLM request failed", label)
            raise LLMError(f"LLM request failed: {exc}") from exc

        total_time = time.monotonic() - start
        content = "".join(chunks)

        if not content:
            raise LLMError(f"[{label}] LLM returned an empty response.")

        input_token_count = estimate_tokens(
            "\n".join(f"{m.get('role', '')}: {m.get('content', '')}" for m in messages)
        )
        output_token_count = estimate_tokens(content)
        gen_time = total_time - ttft
        metrics = {
            "TTFT": round(ttft, 4),
            "TT": round(total_time, 4),
            "Gen_Time": round(gen_time, 4),
            "TPT": round(gen_time / output_token_count if output_token_count > 0 else 0, 6),
            "est_tokens": output_token_count,
            "est_input_tokens": input_token_count,
            "est_output_tokens": output_token_count,
            "est_total_tokens": input_token_count + output_token_count,
        }

        logger.debug("[%s] Response received: %d chars, metrics=%s", label, len(content), metrics)
        return content, metrics

    async def extract_full(
        self,
        messages: list[dict[str, str]],
        *,
        label: str = "full_extraction",
    ) -> tuple[ClinicalExtraction, dict[str, float]]:
        """
        Call the LLM and parse the result as a full ClinicalExtraction.
        Used for the very first chunk if no state exists yet.
        """
        content, metrics = await self.call(messages, label=label)

        try:
            parsed = parse_json_object(content)
        except ParseError as exc:
            raise LLMError(str(exc)) from exc

        normalized = normalize_extraction(parsed)

        try:
            extraction = ClinicalExtraction.model_validate(normalized)
        except ValidationError as exc:
            logger.exception("[%s] Validation failed", label)
            raise LLMError("LLM response did not match ClinicalExtraction schema.") from exc

        if self.settings.log_ai_json:
            logger.info("[%s] Extraction JSON:\n%s", label, extraction.model_dump_json(indent=2))

        return extraction, metrics

    async def extract_intake_full(
        self,
        messages: list[dict[str, str]],
        *,
        label: str = "intake_full_extraction",
    ) -> tuple[IntakeExtraction, dict[str, float]]:
        """
        Call the LLM and parse the result as a full IntakeExtraction (AI outbound
        intake call — a narrower sibling of extract_full/ClinicalExtraction).
        """
        content, metrics = await self.call(messages, label=label)

        try:
            parsed = parse_json_object(content)
        except ParseError as exc:
            raise LLMError(str(exc)) from exc

        # normalize_extraction only knows the 11 ClinicalExtraction section names, but it
        # no-ops safely on keys it doesn't recognize (isinstance check), so calling it here
        # still normalizes "items" for the 5 sections intake shares with the full schema —
        # only current_medication misses that benefit.
        normalized = normalize_extraction(parsed)

        try:
            extraction = IntakeExtraction.model_validate(normalized)
        except ValidationError as exc:
            logger.exception("[%s] Validation failed", label)
            raise LLMError("LLM response did not match IntakeExtraction schema.") from exc

        if self.settings.log_ai_json:
            logger.info("[%s] Intake extraction JSON:\n%s", label, extraction.model_dump_json(indent=2))

        return extraction, metrics

    async def extract_delta(
        self,
        messages: list[dict[str, str]],
        *,
        label: str = "delta_update",
    ) -> tuple[dict[str, Any], dict[str, float]]:
        """
        Call the LLM and parse the result as a delta patch dict.
        Returns ({}, metrics) if the LLM says nothing changed.
        """
        content, metrics = await self.call(messages, label=label)

        content_stripped = content.strip()
        if content_stripped in ("{}", "{ }"):
            logger.debug("[%s] No changes from LLM", label)
            return {}, metrics

        try:
            patch = parse_json_object(content)
        except ParseError as exc:
            logger.warning("[%s] Could not parse delta patch: %s", label, exc)
            return {}, metrics  # Graceful degradation — skip bad patch

        if self.settings.log_ai_json:
            import json
            logger.info("[%s] Delta patch:\n%s", label, json.dumps(patch, indent=2))

        return patch, metrics

    async def extract_interactions(
        self,
        messages: list[dict[str, str]],
        *,
        label: str = "drug_interactions",
    ) -> tuple[list[dict[str, Any]], dict[str, float]]:
        """
        Call the LLM and parse the result as a drug_interactions list.
        Returns ([], metrics) if parsing fails or the response is malformed —
        a broken interaction check must never block sending the final consultation.
        """
        content, metrics = await self.call(messages, label=label)

        try:
            parsed = parse_json_object(content)
        except ParseError as exc:
            logger.warning("[%s] Could not parse interactions response: %s", label, exc)
            return [], metrics

        raw = parsed.get("drug_interactions", [])
        if not isinstance(raw, list):
            return [], metrics

        if self.settings.log_ai_json:
            import json
            logger.info("[%s] Drug interactions:\n%s", label, json.dumps(raw, indent=2))

        return raw, metrics

    async def extract_red_flags(
        self,
        messages: list[dict[str, str]],
        *,
        label: str = "red_flags",
    ) -> tuple[list[dict[str, Any]], dict[str, float]]:
        """
        Call the LLM and parse the result as a red_flags list (AI outbound intake
        call safety screen). Returns ([], metrics) if parsing fails or the response
        is malformed — a broken red-flag check must never block the live call.
        """
        content, metrics = await self.call(messages, label=label)

        try:
            parsed = parse_json_object(content)
        except ParseError as exc:
            logger.warning("[%s] Could not parse red flags response: %s", label, exc)
            return [], metrics

        raw = parsed.get("red_flags", [])
        if not isinstance(raw, list):
            return [], metrics

        if self.settings.log_ai_json:
            import json
            logger.info("[%s] Red flags:\n%s", label, json.dumps(raw, indent=2))

        return raw, metrics

    async def extract_transcript_turns(
        self,
        messages: list[dict[str, str]],
        *,
        label: str = "transcript_turns",
    ) -> tuple[list[dict[str, Any]], dict[str, float]]:
        """
        Call the LLM and parse the result as a list of new transcript turns.
        Returns ([], metrics) if parsing fails or the response is malformed —
        a broken transcript-structuring call must never block the clinical pipeline.
        """
        content, metrics = await self.call(messages, label=label)

        try:
            parsed = parse_json_object(content)
        except ParseError as exc:
            logger.warning("[%s] Could not parse transcript turns response: %s", label, exc)
            return [], metrics

        raw = parsed.get("turns", [])
        if not isinstance(raw, list):
            return [], metrics

        if self.settings.log_ai_json:
            import json
            logger.info("[%s] New transcript turns:\n%s", label, json.dumps(raw, indent=2))

        return raw, metrics

    async def generate_agent_turn(
        self,
        messages: list[dict[str, str]],
        *,
        label: str = "agent_turn",
    ) -> tuple[str, dict[str, float]]:
        """
        Plain-text generation (json_mode=False) — the AI intake agent's actual
        spoken line for the current script step, given its instruction and the
        transcript so far. Raises LLMError on failure (dynamic call playback
        cannot proceed without an agent line — the caller decides how to degrade).
        """
        content, metrics = await self.call(messages, label=label, json_mode=False)
        return content.strip(), metrics

    async def generate_patient_turn(
        self,
        messages: list[dict[str, str]],
        *,
        label: str = "patient_turn",
    ) -> tuple[str, dict[str, float]]:
        """
        Plain-text generation (json_mode=False) — the LLM-simulated patient's
        reply, given a short scenario description and the transcript so far.
        """
        content, metrics = await self.call(messages, label=label, json_mode=False)
        return content.strip(), metrics
