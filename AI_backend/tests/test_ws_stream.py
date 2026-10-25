"""
tests/test_ws_stream.py — Integration test for the WebSocket streaming endpoint.

Requires the server to be running:
    conda activate dev && uvicorn app.main:app --host 127.0.0.1 --port 8001

Uses a pre-recorded WAV file (or generates silent PCM if no WAV is available).
Streams it in real-time over WebSocket and validates:
  - Interim messages arrive for each chunk
  - Final message contains a valid ClinicalExtraction JSON

Run with:
    conda activate dev && python -m pytest tests/test_ws_stream.py -v -s
"""
import json
import struct
import time
import wave
from pathlib import Path

import pytest
import websockets


# ---------------------------------------------------------------------------
# Config
# ---------------------------------------------------------------------------

WS_URL = "ws://127.0.0.1:8001/ws/stream"
WAV_PATH = Path(__file__).resolve().parent.parent.parent / "mock_consultation.wav"
STREAM_CHUNK_MS = 100       # Stream 100ms of audio at a time (simulates real mic)
SAMPLE_RATE = 16000
CHANNELS = 1
BIT_DEPTH = 16

REQUIRED_CLINICAL_KEYS = [
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


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def _read_wav_as_pcm(wav_path: Path) -> bytes:
    """Read a WAV file and return raw PCM bytes (resampled to 16kHz mono if needed)."""
    with wave.open(str(wav_path), "rb") as wf:
        # Read as-is (assumes the WAV is already 16kHz mono PCM)
        return wf.readframes(wf.getnframes())


def _generate_silent_pcm(duration_seconds: float) -> bytes:
    """Generate silent PCM audio for testing when no WAV file is available."""
    num_bytes = int(duration_seconds * SAMPLE_RATE * CHANNELS * (BIT_DEPTH // 8))
    return b"\x00" * num_bytes


def _get_pcm_data() -> bytes:
    """Return PCM bytes — from WAV file if available, else generate silence."""
    if WAV_PATH.exists():
        print(f"\nUsing WAV file: {WAV_PATH}")
        return _read_wav_as_pcm(WAV_PATH)
    else:
        print(f"\nWAV file not found at {WAV_PATH}. Using 3 minutes of silent PCM.")
        return _generate_silent_pcm(180)  # 3 minutes


# ---------------------------------------------------------------------------
# Tests
# ---------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_websocket_streaming_full():
    """
    Full end-to-end integration test.
    Streams audio over WebSocket and validates the final result.
    """
    pcm_data = _get_pcm_data()

    frame_size_bytes = int(STREAM_CHUNK_MS / 1000 * SAMPLE_RATE * CHANNELS * (BIT_DEPTH // 8))
    total_frames = len(pcm_data) // frame_size_bytes

    print(f"PCM size: {len(pcm_data)} bytes (~{len(pcm_data) / (SAMPLE_RATE * 2):.1f}s of audio)")
    print(f"Frame size: {frame_size_bytes} bytes ({STREAM_CHUNK_MS}ms)")
    print(f"Total frames to send: {total_frames}")

    interim_messages = []
    final_message = None

    try:
        async with websockets.connect(WS_URL, ping_interval=30) as ws:
            print("WebSocket connected.")

            # Stream audio frames
            for i in range(total_frames):
                frame = pcm_data[i * frame_size_bytes: (i + 1) * frame_size_bytes]
                await ws.send(frame)

                # Check for messages without blocking
                try:
                    msg = await asyncio.wait_for(ws.recv(), timeout=0.01)
                    data = json.loads(msg)
                    msg_type = data.get("type")
                    if msg_type == "interim":
                        interim_messages.append(data)
                        print(f"  [interim] chunk={data.get('chunk')} transcript_len={len(data.get('transcript', ''))}")
                    elif msg_type == "status":
                        print(f"  [status] {data.get('message')}")
                    elif msg_type == "error":
                        pytest.fail(f"Server returned error: {data.get('message')}")
                except asyncio.TimeoutError:
                    pass  # No message yet, continue streaming

                # Real-time pacing
                await asyncio.sleep(STREAM_CHUNK_MS / 1000)

            # Signal end of audio
            await ws.send(json.dumps({"type": "end"}))
            print("Sent end signal. Waiting for final result...")

            # Wait for the final message
            deadline = time.time() + 300  # 5 minute timeout
            while time.time() < deadline:
                try:
                    msg = await asyncio.wait_for(ws.recv(), timeout=5.0)
                    data = json.loads(msg)
                    msg_type = data.get("type")

                    if msg_type == "interim":
                        interim_messages.append(data)
                        print(f"  [interim] chunk={data.get('chunk')}")
                    elif msg_type == "status":
                        print(f"  [status] {data.get('message')}")
                    elif msg_type == "final":
                        final_message = data
                        print(f"  [FINAL] run_id={data.get('run_id')}")
                        break
                    elif msg_type == "error":
                        pytest.fail(f"Server returned error: {data.get('message')}")
                except asyncio.TimeoutError:
                    continue

    except ConnectionRefusedError:
        pytest.skip(
            f"Server not running at {WS_URL}. "
            "Start it with: uvicorn app.main:app --host 127.0.0.1 --port 8001"
        )

    # ---------------------------------------------------------------------------
    # Assertions
    # ---------------------------------------------------------------------------

    assert final_message is not None, "No final message received"
    assert final_message.get("type") == "final"
    assert "run_id" in final_message
    assert "result" in final_message

    result = final_message["result"]

    # All required clinical keys present
    for key in REQUIRED_CLINICAL_KEYS:
        assert key in result, f"Missing key in final result: {key}"

    # Each key has the right structure
    for key in REQUIRED_CLINICAL_KEYS:
        section = result[key]
        assert isinstance(section, dict), f"{key} should be a dict"
        assert "text" in section, f"{key} missing 'text'"
        assert "items" in section, f"{key} missing 'items'"
        assert "suggestions" in section, f"{key} missing 'suggestions'"
        assert isinstance(section["items"], list), f"{key}.items should be a list"
        assert isinstance(section["suggestions"], list), f"{key}.suggestions should be a list"

    # Prescription has medications
    assert "medications" in result["prescription"]
    assert isinstance(result["prescription"]["medications"], list)

    # Clinical examination has vitals
    assert "vitals" in result["clinical_examination"]

    # Diagnosis has icd10
    assert "icd10" in result["diagnosis"]

    print(f"\nTest passed! Received {len(interim_messages)} interim messages.")
    print(f"Final run_id: {final_message['run_id']}")


@pytest.mark.asyncio
async def test_websocket_connection_and_end_signal():
    """
    Lightweight test: just connects, sends a tiny bit of audio, sends end, waits for final.
    Does NOT validate clinical content — just the protocol.
    """
    # 1 second of silence
    pcm_data = _generate_silent_pcm(1.0)

    try:
        async with websockets.connect(WS_URL) as ws:
            # Send audio
            await ws.send(pcm_data)
            # Send end
            await ws.send(json.dumps({"type": "end"}))

            # Collect messages until final or timeout
            deadline = time.time() + 120
            got_final = False
            while time.time() < deadline:
                try:
                    msg = await asyncio.wait_for(ws.recv(), timeout=5.0)
                    data = json.loads(msg)
                    if data.get("type") == "final":
                        got_final = True
                        break
                    elif data.get("type") == "error":
                        # Error is acceptable (e.g. no Deepgram key) — protocol worked
                        got_final = True
                        break
                except asyncio.TimeoutError:
                    continue

            assert got_final, "Did not receive final or error message within timeout"

    except ConnectionRefusedError:
        pytest.skip(f"Server not running at {WS_URL}")


# ---------------------------------------------------------------------------
# Import asyncio for the timeout helper used in test
# ---------------------------------------------------------------------------
import asyncio
