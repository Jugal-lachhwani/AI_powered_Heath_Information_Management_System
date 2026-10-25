#!/usr/bin/env python3
"""
test_scripts/mock_ws_server.py — Stand-in for the real AI_backend WS server.

Makes ZERO calls to Deepgram/Gemini. Ignores whatever audio the client sends
and replies with a hand-crafted, realistic canned JSON — so the real frontend
rendering code (renderConfidenceScores, renderDrugInteractions,
applyClinicalExtraction, renderTranscriptTurns, etc.) can be visually verified
against known-good data while the real LLM quota is exhausted.

Usage:
    cd AI_backend
    source .venv/bin/activate
    python test_scripts/mock_ws_server.py

Then point the frontend at ws://127.0.0.1:8001/ws/stream as usual (it already
is, by default) and trigger either the mic flow (record a couple seconds,
stop) or the file-upload flow (any short audio file) — content doesn't
matter, this server never looks at it.
"""
from __future__ import annotations

import asyncio
import json

import websockets

HOST = "127.0.0.1"
PORT = 8001

# ---------------------------------------------------------------------------
# Canned data — confidence values deliberately span all three display tiers:
#   >= 0.8 -> high (green), >= 0.4 -> mid (blue), < 0.4 -> low (amber)
# ---------------------------------------------------------------------------

INTERIM_STATE = {
    "chief_complaint": {"text": "Fever, cough, body pain for 3 days", "items": ["Fever", "Cough", "Body pain"], "suggestions": [], "confidence": 1.0},
    "history_of_present_illness": {"text": "Fever started 3 days ago, mild initially, spiked to 101F yesterday evening.", "items": [], "suggestions": [], "confidence": 0.8},
    "past_history": {"text": "", "items": [], "suggestions": [], "confidence": 0.0},
    "family_history": {"text": "", "items": [], "suggestions": [], "confidence": 0.0},
    "drug_allergy": {"text": "", "items": [], "suggestions": [], "confidence": 0.0},
    "clinical_examination": {"text": "", "items": [], "vitals": [], "general_examination": "", "systemic_examination": "", "suggestions": [], "confidence": 0.0},
    "diagnosis": {"text": "", "items": [], "icd10": [], "suggestions": [], "confidence": 0.0},
    "investigations": {"text": "", "items": [], "suggestions": [], "confidence": 0.0},
    "prescription": {"text": "", "items": [], "medications": [], "suggestions": [], "confidence": 0.0},
    "advice": {"text": "", "items": [], "suggestions": [], "confidence": 0.0},
    "follow_up": {"text": "", "items": [], "date_text": "", "instruction": "", "suggestions": [], "confidence": 0.0},
}

INTERIM_TRANSCRIPT_TURNS = [
    {"speaker": "Doctor", "text": "Hello. Am I speaking to Mr. ABC? This is Doctor Sharma."},
    {"speaker": "Patient", "text": "Yes, doctor. It's ABC here. My throat is killing me."},
    {"speaker": "Doctor", "text": "Good morning. How can I help you today?"},
    {"speaker": "Patient", "text": "It's this fever, and I have a cough and body pain everywhere."},
]

FINAL_RESULT = {
    "chief_complaint": {"text": "Fever, cough, body pain, and exhaustion for 3 days", "items": ["Fever", "Cough", "Body pain", "Exhaustion"], "suggestions": [], "confidence": 1.0},
    "history_of_present_illness": {"text": "Fever started 3 days ago, mild initially, spiked to 101F yesterday evening. Paracetamol gave temporary relief.", "items": [], "suggestions": [], "confidence": 0.9},
    "past_history": {"text": "Hypertension diagnosed 5 years ago, on Amlodipine 5mg once daily.", "items": ["Hypertension (5 years)", "On Amlodipine 5mg OD"], "suggestions": [], "confidence": 0.9},
    "family_history": {"text": "Father has diabetes. Mother has hypertension.", "items": ["Father: Diabetes", "Mother: Hypertension"], "suggestions": [], "confidence": 1.0},
    "drug_allergy": {"text": "Allergic to Penicillin, develops skin rash.", "items": ["Penicillin (skin rash)"], "suggestions": [], "confidence": 1.0},
    "clinical_examination": {
        "text": "Temp 100.8F, BP 132/84, Pulse 92/min, SpO2 98%. Conscious, oriented, mildly febrile.",
        "items": [],
        "vitals": [
            {"label": "BP", "value": "132/84", "unit": "mmHg"},
            {"label": "Pulse", "value": "92", "unit": "/min"},
            {"label": "Temp", "value": "100.8", "unit": "°F"},
            {"label": "SpO2", "value": "98", "unit": "%"},
        ],
        "general_examination": "Conscious, oriented, mildly febrile, not in respiratory distress.",
        "systemic_examination": "Mild pharyngeal congestion noted.",
        "suggestions": [],
        "confidence": 0.7,
    },
    "diagnosis": {
        "text": "Provisional: Acute viral upper respiratory tract infection (viral fever with pharyngitis).",
        "items": ["Acute viral URTI", "Viral fever with pharyngitis"],
        "icd10": [],
        "suggestions": ["Consider confirming with CBC/CRP if fever persists beyond 5 days."],
        "confidence": 0.6,
    },
    "investigations": {"text": "CBC, CRP, COVID-19/influenza test if indicated.", "items": ["CBC", "CRP", "COVID-19/influenza test if indicated"], "suggestions": [], "confidence": 0.8},
    "prescription": {
        "text": "Paracetamol 650mg Q6H PRN, continue Amlodipine 5mg OD, Levocetirizine 5mg OD at night x5 days.",
        "items": [],
        "medications": [
            {"medicine": "Paracetamol", "dose": "650 mg", "route": "Oral", "frequency": "Every 6 hours PRN", "duration": "As needed", "remarks": "If fever > 100°F, max 4/day"},
            {"medicine": "Amlodipine", "dose": "5 mg", "route": "Oral", "frequency": "Once daily", "duration": "Continue", "remarks": ""},
            {"medicine": "Levocetirizine", "dose": "5 mg", "route": "Oral", "frequency": "Once daily at night", "duration": "5 days", "remarks": ""},
        ],
        "suggestions": [],
        "confidence": 0.9,
    },
    "advice": {"text": "Warm saline gargles, steam inhalation, plenty of fluids, rest.", "items": ["Warm saline gargles 3-4x daily", "Steam inhalation twice daily", "Plenty of fluids", "Adequate rest"], "suggestions": [], "confidence": 0.5},
    "follow_up": {"text": "Review after 3 days or earlier if symptoms worsen.", "items": ["Review after 3 days"], "date_text": "After 3 days", "instruction": "Earlier if symptoms worsen", "suggestions": [], "confidence": 0.3},
    "drug_interactions": [
        {"drug_a": "Paracetamol", "drug_b": "Amlodipine", "severity": "minor", "description": "No clinically significant interaction; monitor as routine practice."}
    ],
    "transcript_turns": INTERIM_TRANSCRIPT_TURNS + [
        {"speaker": "Doctor", "text": "I'm prescribing Paracetamol for the fever and Levocetirizine for the throat."},
        {"speaker": "Patient", "text": "Okay doctor, thank you. I'll follow your instructions."},
    ],
}


async def handle_client(ws):
    print(f"[mock] client connected: {ws.remote_address}")
    await ws.send(json.dumps({"type": "status", "run_id": "mock", "message": "Session started. run_id=mock (using canned test data, no LLM calls)"}))

    sent_interim = False
    frame_count = 0

    try:
        async for message in ws:
            if isinstance(message, bytes):
                frame_count += 1
                if not sent_interim and frame_count >= 1:
                    sent_interim = True
                    print("[mock] sending interim message")
                    await ws.send(json.dumps({
                        "type": "interim",
                        "run_id": "mock",
                        "chunk": 1,
                        "transcript": "Hello, am I speaking to Mr. ABC? This is Doctor Sharma...",
                        "state": INTERIM_STATE,
                        "transcript_turns": INTERIM_TRANSCRIPT_TURNS,
                    }))
            else:
                try:
                    control = json.loads(message)
                except json.JSONDecodeError:
                    continue
                if control.get("type") == "end":
                    print("[mock] received end signal, sending final")
                    await ws.send(json.dumps({
                        "type": "final",
                        "run_id": "mock",
                        "result": FINAL_RESULT,
                    }))
                    break
    except websockets.exceptions.ConnectionClosed:
        print("[mock] client disconnected")

    await ws.close()
    print("[mock] session closed")


async def main():
    print(f"[mock] Listening on ws://{HOST}:{PORT}/ws/stream (canned data only, no LLM/STT calls)")
    async with websockets.serve(handle_client, HOST, PORT):
        await asyncio.Future()  # run forever


if __name__ == "__main__":
    asyncio.run(main())
