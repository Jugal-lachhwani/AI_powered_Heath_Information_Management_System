"""
routes/health.py — Health check endpoint.
"""
from __future__ import annotations

import logging

from fastapi import APIRouter

from ..config import settings
from ..llm.client import LLMClient

logger = logging.getLogger(__name__)

router = APIRouter()

# LLM client is injected via the app state — but for health we create a lightweight check
_llm_client: LLMClient | None = None


def set_llm_client(client: LLMClient) -> None:
    global _llm_client
    _llm_client = client


@router.get("/health")
async def health() -> dict:
    """
    Returns server health and LLM availability status.
    """
    model_ok = True
    if _llm_client is not None:
        model_ok = await _llm_client.is_model_available()

    return {
        "status": "ok",
        "llm": {
            "provider": settings.llm_provider,
            "model": settings.llm_model,
            "model_available": model_ok,
        },
        "stt": {
            "provider": "sarvam",
            "model": settings.sarvam_model,
            "language": settings.sarvam_language,
            "api_key_set": bool(settings.sarvam_api_key),
        },
        "pipeline": {
            "chunk_seconds": settings.chunk_seconds,
            "overlap_seconds": settings.overlap_seconds,
            "sample_rate": settings.sample_rate,
            "prompt_version": settings.prompt_version,
        },
    }
