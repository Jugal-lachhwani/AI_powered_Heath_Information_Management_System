"""
config.py — Configuration decisions ONLY.

This file contains ONLY the settings/decisions for the application.
No business logic, no URL construction, no API key resolution here.
All logic lives in the modules that consume these settings.
"""
from __future__ import annotations

import os
from dataclasses import dataclass, field
from pathlib import Path

from dotenv import load_dotenv

load_dotenv(override=True)


# ---------------------------------------------------------------------------
# Helpers (pure env parsing — no domain logic)
# ---------------------------------------------------------------------------

def _float_env(name: str, default: float) -> float:
    value = os.getenv(name)
    if value is None or value.strip() == "":
        return default
    try:
        return float(value)
    except ValueError:
        return default


def _int_env(name: str, default: int) -> int:
    value = os.getenv(name)
    if value is None or value.strip() == "":
        return default
    try:
        return int(value)
    except ValueError:
        return default


def _bool_env(name: str, default: bool) -> bool:
    value = os.getenv(name)
    if value is None or value.strip() == "":
        return default
    return value.strip().lower() in {"1", "true", "yes", "on"}


# ---------------------------------------------------------------------------
# Settings dataclass — pure data, no methods
# ---------------------------------------------------------------------------

@dataclass(frozen=True)
class Settings:
    # ------------------------------------------------------------------
    # LLM decisions
    # ------------------------------------------------------------------
    llm_provider: str           # openai | grok | nvidia | gemini | groq | ollama
    llm_model: str
    llm_temperature: float
    llm_timeout_seconds: float
    prompt_version: str         # e.g. "v1", "v2" — selects which prompt to use

    # ------------------------------------------------------------------
    # Streaming / chunking decisions
    # ------------------------------------------------------------------
    chunk_seconds: int          # window size for each audio chunk
    overlap_seconds: int        # overlap between consecutive chunks
    sample_rate: int            # Hz — must match what the client sends
    channels: int               # 1 = mono
    bit_depth: int              # 16 = PCM16

    # ------------------------------------------------------------------
    # STT decisions
    # ------------------------------------------------------------------
    sarvam_api_key: str
    sarvam_language: str        # e.g. "hi-IN", "gu-IN", "en-IN" (BCP-47)
    sarvam_model: str           # e.g. "saaras:v3", "saarika:v2.5"

    # Deepgram settings kept (unused — see app/pipeline/stt.py) for easy rollback.
    deepgram_api_key: str
    deepgram_language: str      # e.g. "en-IN", "en-US"
    deepgram_model: str         # e.g. "nova-2", "whisper-large"

    # ------------------------------------------------------------------
    # Paths
    # ------------------------------------------------------------------
    results_dir: Path           # where run folders are written

    # ------------------------------------------------------------------
    # API keys (raw — resolution to base_url happens in llm/client.py)
    # ------------------------------------------------------------------
    openai_api_key: str
    grok_api_key: str
    nvidia_api_key: str
    gemini_api_key: str
    groq_api_key: str
    ollama_base_url: str

    # ------------------------------------------------------------------
    # App decisions
    # ------------------------------------------------------------------
    cors_origins: tuple[str, ...]
    log_ai_json: bool           # whether to log full JSON to app log


# ---------------------------------------------------------------------------
# Factory — single place where env vars are read
# ---------------------------------------------------------------------------

def load_settings() -> Settings:
    origins_raw = os.getenv("CORS_ORIGINS", "*")
    cors_origins = tuple(
        origin.strip() for origin in origins_raw.split(",") if origin.strip()
    ) or ("*",)

    results_dir = Path(
        os.getenv("RESULTS_DIR", str(Path(__file__).resolve().parent.parent / ".results"))
    )

    return Settings(
        # LLM
        llm_provider=os.getenv("LLM_PROVIDER", "ollama").strip(),
        llm_model=os.getenv("LLM_MODEL", "qwen2.5:7b").strip(),
        llm_temperature=_float_env("LLM_TEMPERATURE", 0.0),
        llm_timeout_seconds=_float_env("LLM_TIMEOUT_SECONDS", 360.0),
        prompt_version=os.getenv("PROMPT_VERSION", "v1").strip(),

        # Chunking
        chunk_seconds=_int_env("CHUNK_SECONDS", 60),
        overlap_seconds=_int_env("OVERLAP_SECONDS", 10),
        sample_rate=_int_env("SAMPLE_RATE", 16000),
        channels=_int_env("CHANNELS", 1),
        bit_depth=_int_env("BIT_DEPTH", 16),

        # STT
        sarvam_api_key=os.getenv("SARVAM_API_KEY", "").strip(),
        sarvam_language=os.getenv("SARVAM_LANGUAGE", "hi-IN").strip(),
        sarvam_model=os.getenv("SARVAM_MODEL", "saaras:v3").strip(),

        deepgram_api_key=os.getenv("DEEPGRAM_API_KEY", "").strip(),
        deepgram_language=os.getenv("DEEPGRAM_LANGUAGE", "en-IN").strip(),
        deepgram_model=os.getenv("DEEPGRAM_MODEL", "nova-2").strip(),

        # Paths
        results_dir=results_dir,

        # API keys
        openai_api_key=os.getenv("OPENAI_API_KEY", "").strip(),
        grok_api_key=os.getenv("GROK_API_KEY", "").strip(),
        nvidia_api_key=os.getenv("NVIDIA_API_KEY", "").strip(),
        gemini_api_key=os.getenv("GEMINI_API_KEY", "").strip(),
        groq_api_key=os.getenv("GROQ_API_KEY", "").strip(),
        ollama_base_url=os.getenv("OLLAMA_BASE_URL", "http://127.0.0.1:11434/v1").rstrip("/"),

        # App
        cors_origins=cors_origins,
        log_ai_json=_bool_env("LOG_AI_JSON", True),
    )


# Module-level singleton
settings = load_settings()
