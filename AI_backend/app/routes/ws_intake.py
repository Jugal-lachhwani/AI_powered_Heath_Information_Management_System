"""
routes/ws_intake.py — AI outbound intake call WebSocket endpoint (mocked telephony).

Single endpoint: /ws/intake
No audio at all — either a canned script (mode=fixed, see pipeline/mock_intake_scripts.py)
or a doctor-authored script + LLM-generated turns (mode=dynamic, the default — see
pipeline/dynamic_intake_session.py) is played through the real extraction/red-flag LLM
pipeline. Structural mirror of routes/ws_stream.py's router-registration pattern.
"""
from __future__ import annotations

import logging

from fastapi import APIRouter, WebSocket

from ..config import settings
from ..llm.client import LLMClient
from ..pipeline.dynamic_intake_session import DynamicIntakeCallSession
from ..pipeline.intake_call_session import IntakeCallSession
from ..pipeline.mock_intake_scripts import DEFAULT_SCRIPT_KEY

logger = logging.getLogger(__name__)

router = APIRouter()

_llm_client: LLMClient | None = None


def set_llm_client(client: LLMClient) -> None:
    global _llm_client
    _llm_client = client


@router.websocket("/ws/intake")
async def intake_call(websocket: WebSocket) -> None:
    """
    WebSocket endpoint for a mocked AI outbound intake call.

    Query params:
      - mode: "dynamic" or "fixed". If omitted: "fixed" when a `script` param is given
        (backward-compatible with callers written before dynamic mode existed, e.g.
        test_scripts/stream_intake_call.py), otherwise "dynamic".
        dynamic: client must send {"type":"init","steps":[...],"scenario_text":"...",
          "patient_name":"..."} as its first frame — see DynamicIntakeCallSession.
        fixed: plays one of mock_intake_scripts.SCRIPTS end to end, no init frame needed.
      - script: (mode=fixed only) which canned script to play. Defaults to "chest_pain_flag".

    See the relevant session class's module docstring for the full message protocol
    (identical between modes except for the dynamic-only "init" frame).
    """
    await websocket.accept()
    default_mode = "fixed" if websocket.query_params.get("script") else "dynamic"
    mode = websocket.query_params.get("mode", default_mode)
    logger.info("Intake WebSocket connection accepted (mode=%s)", mode)

    client = _llm_client
    if client is None:
        await websocket.send_text('{"type": "error", "message": "Server not ready — LLM client not initialized"}')
        await websocket.close()
        return

    if mode == "fixed":
        script_key = websocket.query_params.get("script", DEFAULT_SCRIPT_KEY)
        session = IntakeCallSession(
            websocket=websocket, settings=settings, llm_client=client, script_key=script_key,
        )
    else:
        session = DynamicIntakeCallSession(websocket=websocket, settings=settings, llm_client=client)

    try:
        await session.run()
    except Exception as exc:
        logger.exception("Unhandled exception in intake session %s", session.run_id)
        try:
            await websocket.send_text(f'{{"type": "error", "message": "{exc}"}}')
        except Exception:
            pass
    finally:
        try:
            await websocket.close()
        except Exception:
            pass
        logger.info("Intake WebSocket session %s closed", session.run_id)
