#!/usr/bin/env python3
"""
test_scripts/stream_dynamic_intake_call.py — WS client for dynamic-mode AI intake
calls (/ws/intake?mode=dynamic). Sends the required {"type":"init",...} handshake
frame with a hardcoded steps list (mirroring the seeded default agent script) and
a scenario, then prints every message like stream_intake_call.py does.

Usage:
    python test_scripts/stream_dynamic_intake_call.py
    python test_scripts/stream_dynamic_intake_call.py --scenario "ongoing dizziness and blurred vision for two days"
"""
from __future__ import annotations

import argparse
import asyncio
import json
import time
from datetime import datetime
from pathlib import Path

import websockets

PORT = 8001
WS_URL = f"ws://127.0.0.1:{PORT}/ws/intake?mode=dynamic"

COLORS = {
    "RESET": "\033[0m", "BOLD": "\033[1m", "GREEN": "\033[92m",
    "YELLOW": "\033[93m", "CYAN": "\033[96m", "RED": "\033[91m", "DIM": "\033[2m",
}


def c(text: str, color: str) -> str:
    return f"{COLORS.get(color, '')}{text}{COLORS['RESET']}"


DEFAULT_STEPS = [
    {"step_key": "identity", "title": "Verify identity", "fills_field": "",
     "prompt_text": "Ask \"Am I speaking with [patient_name]? I'm calling from the hospital on behalf of [doctor].\""},
    {"step_key": "consent", "title": "Recording consent", "fills_field": "",
     "prompt_text": "Ask for permission to record the call, explaining it is shared only with their doctor."},
    {"step_key": "complaint", "title": "Chief complaint", "fills_field": "chief_complaint",
     "prompt_text": "Ask what is troubling them the most today. Let them finish. Never suggest a symptom they did not mention."},
    {"step_key": "history", "title": "History of present illness", "fills_field": "history_of_present_illness",
     "prompt_text": "Ask about onset, duration, severity, triggers, relief, and any relevant past illnesses, surgeries, or family history."},
    {"step_key": "red_flags", "title": "Red flag screen", "fills_field": "",
     "prompt_text": "Ask specifically about chest pain, breathlessness, bleeding, sudden weakness, or thoughts of self-harm."},
    {"step_key": "meds", "title": "Medication & allergies", "fills_field": "current_medication",
     "prompt_text": "Ask what medication they currently take and whether they have any drug allergies. Repeat drug names back to confirm."},
    {"step_key": "wrapup", "title": "Wrap up", "fills_field": "",
     "prompt_text": "Thank the patient, confirm their information has been shared with the doctor, and end the call."},
]


async def stream(scenario: str, patient_name: str) -> None:
    print(c(f"\n[STREAM] Connecting to {WS_URL}", "CYAN"))
    turn_count = 0
    start_time = time.monotonic()

    async with websockets.connect(WS_URL, ping_interval=20, ping_timeout=60) as ws:
        print(c("[STREAM] Connected! Sending init frame...", "GREEN"))
        await ws.send(json.dumps({
            "type": "init", "steps": DEFAULT_STEPS,
            "scenario_text": scenario, "patient_name": patient_name,
        }))

        while True:
            msg = json.loads(await ws.recv())
            msg_type = msg.get("type")

            if msg_type == "call_status":
                print(c(f"[STATUS] {msg}", "DIM"))
            elif msg_type == "turn":
                turn_count += 1
                color = "CYAN" if msg["speaker"] == "Agent" else "GREEN"
                print(f"{c('[' + msg.get('step', '?') + ']', 'DIM')} {c(msg['speaker'] + ':', color)} {msg['text']}")
            elif msg_type == "interim":
                state = msg.get("state", {})
                filled = sum(1 for v in state.values() if isinstance(v, dict) and (v.get("text") or v.get("items")))
                print(c(f"  -- interim: step={msg.get('step')} {filled}/6 sections filled, "
                        f"{len(msg.get('red_flags', []))} red flag(s)", "DIM"))
            elif msg_type == "red_flag":
                flag = msg.get("flag", {})
                print(c(f"\n  !!! RED FLAG: {flag.get('type')} ({flag.get('severity')}) — {flag.get('evidence')}\n", "RED"))
            elif msg_type == "error":
                print(c(f"\n[ERROR] {msg.get('message')}", "RED"))
            elif msg_type == "final":
                result = msg.get("result", {})
                print(f"\n{c('=' * 60, 'GREEN')}")
                print(c("  FINAL DYNAMIC INTAKE DRAFT", "BOLD"))
                print(c("=" * 60, "GREEN"))
                for key in ["chief_complaint", "history_of_present_illness", "past_history",
                            "family_history", "current_medication", "drug_allergy"]:
                    section = result.get(key, {})
                    text = section.get("text", "") if isinstance(section, dict) else ""
                    if text:
                        print(f"\n  {c(key, 'BOLD')} ({round((section.get('confidence') or 0) * 100)}%)")
                        print(f"    {text}")
                print(f"\n  Red flags: {len(result.get('red_flags', []))}  Tasks: {len(result.get('tasks', []))}")
                ts = datetime.now().strftime("%Y%m%d_%H%M%S")
                out_path = Path(f"dynamic_intake_result_{ts}.json")
                out_path.write_text(json.dumps(msg, indent=2, ensure_ascii=False), encoding="utf-8")
                elapsed = time.monotonic() - start_time
                print(c(f"\n[DONE] Total time: {elapsed:.1f}s | Turns: {turn_count}", "GREEN"))
                print(c(f"[DONE] Result saved to: {out_path.resolve()}", "GREEN"))
                return


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Stream-test the dynamic AI intake call WS endpoint.")
    parser.add_argument("--scenario", default="a burning stomach pain for a week, worse at night, taking Pantoprazole already")
    parser.add_argument("--patient-name", default="Test Patient")
    return parser.parse_args()


if __name__ == "__main__":
    args = parse_args()
    asyncio.run(stream(args.scenario, args.patient_name))
