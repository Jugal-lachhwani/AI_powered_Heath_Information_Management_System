"""
main.py — FastAPI application entry point.

This file contains ONLY:
  - FastAPI app creation
  - CORS middleware
  - Logging setup
  - Router includes
  - Application lifespan (startup/shutdown)

NO endpoint logic lives here. All routes are in app/routes/.
"""
from __future__ import annotations

import logging
from contextlib import asynccontextmanager
from pathlib import Path

from fastapi import FastAPI
from fastapi.middleware.cors import CORSMiddleware

from .config import settings
from .llm.client import LLMClient
from .routes import (
    health_router,
    ws_router,
    intake_ws_router,
    set_health_llm_client,
    set_ws_llm_client,
    set_intake_ws_llm_client,
)


# ---------------------------------------------------------------------------
# Logging setup
# ---------------------------------------------------------------------------

_LOG_DIR = Path(__file__).resolve().parent.parent / ".logs"
_LOG_DIR.mkdir(parents=True, exist_ok=True)

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s %(levelname)s [%(name)s] %(message)s",
    handlers=[
        logging.FileHandler(_LOG_DIR / "app.log"),
        logging.StreamHandler(),
    ],
)

logger = logging.getLogger(__name__)


# ---------------------------------------------------------------------------
# Lifespan — startup and shutdown
# ---------------------------------------------------------------------------

@asynccontextmanager
async def lifespan(app: FastAPI):
    # Startup
    logger.info(
        "Starting up. Provider=%s Model=%s Sarvam=%s",
        settings.llm_provider,
        settings.llm_model,
        settings.sarvam_model,
    )

    # Create results directory
    settings.results_dir.mkdir(parents=True, exist_ok=True)
    logger.info("Results directory: %s", settings.results_dir)

    # Initialize shared LLM client
    llm_client = LLMClient(settings)
    set_health_llm_client(llm_client)
    set_ws_llm_client(llm_client)
    set_intake_ws_llm_client(llm_client)

    logger.info("Server ready.")
    yield

    # Shutdown
    logger.info("Shutting down.")


# ---------------------------------------------------------------------------
# App
# ---------------------------------------------------------------------------

app = FastAPI(
    title="HIMS AI Clinical Documentation — Streaming Backend",
    description=(
        "Real-time medical conversation summarization via WebSocket streaming. "
        "Audio is chunked, transcribed, and incrementally structured into clinical JSON."
    ),
    version="2.0.0",
    lifespan=lifespan,
)

app.add_middleware(
    CORSMiddleware,
    allow_origins=list(settings.cors_origins),
    allow_credentials=False,
    allow_methods=["*"],
    allow_headers=["*"],
)

# ---------------------------------------------------------------------------
# Routers
# ---------------------------------------------------------------------------

app.include_router(health_router)      # GET /health
app.include_router(ws_router)          # WS  /ws/stream
app.include_router(intake_ws_router)   # WS  /ws/intake
