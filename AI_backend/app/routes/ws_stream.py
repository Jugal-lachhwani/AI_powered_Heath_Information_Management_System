"""
routes/ws_stream.py — WebSocket streaming endpoint.

Single endpoint: /ws/stream
Accepts binary PCM audio frames and a text {"type": "end"} control frame.
"""
from __future__ import annotations

import logging

from fastapi import APIRouter, WebSocket

from ..config import settings
from ..llm.client import LLMClient
from ..pipeline.session import Session

logger = logging.getLogger(__name__)

router = APIRouter()

# Shared LLM client (stateless — safe to share across sessions)
_llm_client: LLMClient | None = None


def set_llm_client(client: LLMClient) -> None:
    global _llm_client
    _llm_client = client


@router.websocket("/ws/stream")
async def stream_audio(websocket: WebSocket) -> None:
    """
    WebSocket endpoint for live audio streaming.

    Query params:
      - source: "live" (default) for live mic recording, paced in real time —
        uses Sarvam's real-time streaming STT. "upload" for an already-recorded
        file sent far faster than real time — uses batch REST STT instead,
        since the streaming API cannot keep up with non-real-time-paced audio.

    Client sends:
      - Binary frames: raw PCM16 audio at the configured sample rate
      - Text frame: {"type": "end"} to signal end of audio

    Server sends:
      - {"type": "status",  "message": "..."} — progress updates
      - {"type": "interim", "chunk": N, "transcript": "...", "state": {...}}
      - {"type": "final",   "run_id": "...", "result": {...}}
      - {"type": "error",   "message": "..."} — on failure
    """
    await websocket.accept()
    source = websocket.query_params.get("source", "live")
    logger.info("WebSocket connection accepted (source=%s)", source)

    client = _llm_client
    if client is None:
        await websocket.send_text('{"type": "error", "message": "Server not ready — LLM client not initialized"}')
        await websocket.close()
        return

    session = Session(
        websocket=websocket,
        settings=settings,
        llm_client=client,
        source=source,
    )

    try:
        await session.run()
    except Exception as exc:
        logger.exception("Unhandled exception in session %s", session.run_id)
        try:
            await websocket.send_text(f'{{"type": "error", "message": "{exc}"}}')
        except Exception:
            pass
    finally:
        try:
            await websocket.close()
        except Exception:
            pass
        logger.info("WebSocket session %s closed", session.run_id)
