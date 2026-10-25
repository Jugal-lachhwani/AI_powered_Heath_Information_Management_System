#!/usr/bin/env python3
"""
test_scripts/stream_intake_call.py — Standalone WS client for the mocked AI
outbound intake call (/ws/intake). Structural copy of stream_wav.py, but there's
no audio to send at all — the whole "call" is server-driven playback of a canned
script (see app/pipeline/mock_intake_scripts.py).

Usage:
    conda activate dev
    python test_scripts/stream_intake_call.py
    python test_scripts/stream_intake_call.py --script routine_follow_up
    python test_scripts/stream_intake_call.py --whisper-at 3 --whisper-text "ask about aspirin allergy"
    python test_scripts/stream_intake_call.py --barge-in-at 5

Controls:
    Ctrl+C to interrupt early.

Output:
    Real-time display of every message type. Final JSON also saved to
    intake_result_<timestamp>.json.
"""
from __future__ import annotations

import argparse
import asyncio
import json
import time
from datetime import datetime
from pathlib import Path

try:
    import websockets
except ImportError:
    import sys
    print("ERROR: websockets not installed. Run: pip install websockets")
    sys.exit(1)


PORT = 8001
DEFAULT_URL = f"ws://127.0.0.1:{PORT}/ws/intake"

COLORS = {
    "RESET": "\033[0m", "BOLD": "\033[1m", "GREEN": "\033[92m",
    "YELLOW": "\033[93m", "CYAN": "\033[96m", "RED": "\033[91m", "DIM": "\033[2m",
}


def c(text: str, color: str) -> str:
    return f"{COLORS.get(color, '')}{text}{COLORS['RESET']}"


def print_turn(data: dict) -> None:
    speaker = data.get("speaker", "?")
    step = data.get("step", "?")
    text = data.get("text", "")
    color = "CYAN" if speaker == "Agent" else "GREEN"
    print(f"{c(f'[{step}]', 'DIM')} {c(speaker + ':', color)} {text}")
    if data.get("whisper_annotation"):
        print(c(f"    (whisper annotation: {data['whisper_annotation']})", "YELLOW"))


def print_interim(data: dict) -> None:
    state = data.get("state", {})
    filled = sum(1 for v in state.values() if isinstance(v, dict) and (v.get("text") or v.get("items")))
    print(c(f"  -- interim: step={data.get('step')} {filled}/6 sections filled, "
            f"{len(data.get('red_flags', []))} red flag(s), {len(data.get('tasks', []))} task(s)", "DIM"))


def print_red_flag(data: dict) -> None:
    flag = data.get("flag", {})
    print(c(f"\n  !!! RED FLAG: {flag.get('type')} ({flag.get('severity')}) — {flag.get('evidence')}", "RED"))
    if flag.get("suggested_task"):
        print(c(f"      suggested task: {flag['suggested_task']}", "RED"))
    print()


def print_final(data: dict) -> None:
    result = data.get("result", {})
    print(f"\n{c('=' * 60, 'GREEN')}")
    print(c("  FINAL INTAKE DRAFT", "BOLD"))
    print(c(f"  Run ID: {data.get('run_id')}", "DIM"))
    print(c("=" * 60, "GREEN"))
    for key in ["chief_complaint", "history_of_present_illness", "past_history",
                "family_history", "current_medication", "drug_allergy"]:
        section = result.get(key, {})
        text = section.get("text", "") if isinstance(section, dict) else ""
        conf = section.get("confidence", 0.0) if isinstance(section, dict) else 0.0
        if text:
            print(f"\n  {c(key, 'BOLD')} {c(f'({conf * 100:.0f}%)', 'DIM')}")
            print(f"    {text}")
    print(f"\n  {c('Red flags:', 'BOLD')} {len(result.get('red_flags', []))}")
    print(f"  {c('Tasks:', 'BOLD')} {len(result.get('tasks', []))}")
    print(f"  {c('Human taken over:', 'BOLD')} {result.get('human_taken_over')}")


async def stream(
    ws_url: str,
    whisper_at: int | None,
    whisper_text: str,
    barge_in_at: int | None,
) -> None:
    print(c(f"\n[STREAM] Connecting to {ws_url}", "CYAN"))

    turn_count = 0
    start_time = time.monotonic()

    try:
        async with websockets.connect(ws_url, ping_interval=20, ping_timeout=60) as ws:
            print(c("[STREAM] Connected!", "GREEN"))

            while True:
                msg = await ws.recv()
                data = json.loads(msg)
                msg_type = data.get("type")

                if msg_type == "call_status":
                    print(c(f"[STATUS] {data}", "DIM"))
                elif msg_type == "turn":
                    turn_count += 1
                    print_turn(data)
                    if data.get("speaker") == "Patient":
                        if whisper_at is not None and turn_count >= whisper_at:
                            await ws.send(json.dumps({"type": "whisper", "text": whisper_text}))
                            print(c(f"  >> sent whisper: {whisper_text}", "YELLOW"))
                            whisper_at = None
                        if barge_in_at is not None and turn_count >= barge_in_at:
                            await ws.send(json.dumps({"type": "barge_in"}))
                            print(c("  >> sent barge_in", "YELLOW"))
                            barge_in_at = None
                elif msg_type == "interim":
                    print_interim(data)
                elif msg_type == "red_flag":
                    print_red_flag(data)
                elif msg_type == "error":
                    print(c(f"\n[ERROR] {data.get('message')}", "RED"))
                elif msg_type == "final":
                    print_final(data)
                    ts = datetime.now().strftime("%Y%m%d_%H%M%S")
                    out_path = Path(f"intake_result_{ts}.json")
                    out_path.write_text(json.dumps(data, indent=2, ensure_ascii=False), encoding="utf-8")
                    elapsed = time.monotonic() - start_time
                    print(c(f"\n[DONE] Total time: {elapsed:.1f}s | Turns: {turn_count}", "GREEN"))
                    print(c(f"[DONE] Result saved to: {out_path.resolve()}", "GREEN"))
                    return

    except ConnectionRefusedError:
        print(c(f"\n[ERROR] Could not connect to {ws_url}", "RED"))
        print(c("  Is the server running? uvicorn app.main:app --host 127.0.0.1 --port 8001", "DIM"))
    except KeyboardInterrupt:
        print(c("\n[INTERRUPTED] Ctrl+C received.", "YELLOW"))


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Stream-test the mocked AI intake call WS endpoint.")
    parser.add_argument("--script", default="chest_pain_flag", help="script key (default: chest_pain_flag)")
    parser.add_argument("--url", default=None, help=f"WebSocket URL (default: {DEFAULT_URL}?script=...)")
    parser.add_argument("--whisper-at", type=int, default=None, help="send a whisper after this many Patient turns")
    parser.add_argument("--whisper-text", default="ask about aspirin allergy", help="whisper text to send")
    parser.add_argument("--barge-in-at", type=int, default=None, help="send barge_in after this many Patient turns")
    return parser.parse_args()


if __name__ == "__main__":
    args = parse_args()
    ws_url = args.url or f"{DEFAULT_URL}?script={args.script}"
    asyncio.run(stream(
        ws_url=ws_url,
        whisper_at=args.whisper_at,
        whisper_text=args.whisper_text,
        barge_in_at=args.barge_in_at,
    ))
