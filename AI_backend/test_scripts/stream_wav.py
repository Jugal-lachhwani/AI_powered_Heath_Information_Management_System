#!/usr/bin/env python3
"""
test_scripts/stream_wav.py — Standalone WAV-to-WebSocket audio streamer.

Simulates a live microphone by reading a pre-recorded WAV file and streaming
it in real-time to the backend WebSocket endpoint.

Usage:
    conda activate dev
    python test_scripts/stream_wav.py
    python test_scripts/stream_wav.py --wav /path/to/audio.wav
    python test_scripts/stream_wav.py --wav audio.wav --url ws://127.0.0.1:8001/ws/stream
    python test_scripts/stream_wav.py --wav audio.wav --speed 2.0  # Stream at 2x speed

Controls:
    Ctrl+C to interrupt early (will still attempt to send end signal)

Output:
    Real-time display of interim chunks and final result.
    Final JSON is also saved to stream_result_<timestamp>.json
"""
from __future__ import annotations

import argparse
import asyncio
import json
import struct
import time
import wave
from datetime import datetime
from pathlib import Path
import sys

try:
    import websockets
except ImportError:
    print("ERROR: websockets not installed. Run: pip install websockets")
    sys.exit(1)


# ---------------------------------------------------------------------------
# Defaults
# ---------------------------------------------------------------------------

PORT = 8001

DEFAULT_WAV = Path(__file__).resolve().parent.parent.parent / "mock_consultation.wav"
DEFAULT_URL = f"ws://127.0.0.1:{PORT}/ws/stream"
DEFAULT_CHUNK_MS = 100    # Send 100ms of audio per frame (real-time pacing)
STREAM_SAMPLE_RATE = 16000
STREAM_CHANNELS = 1
STREAM_BIT_DEPTH = 16


# ---------------------------------------------------------------------------
# WAV reading
# ---------------------------------------------------------------------------

def read_wav_pcm(wav_path: Path) -> tuple[bytes, int, int, int]:
    """
    Read a WAV file and return (pcm_bytes, sample_rate, channels, bit_depth).
    Converts to mono if stereo. Does NOT resample.
    """
    with wave.open(str(wav_path), "rb") as wf:
        sample_rate = wf.getframerate()
        channels = wf.getnchannels()
        bit_depth = wf.getsampwidth() * 8
        pcm_bytes = wf.readframes(wf.getnframes())

    # Simple stereo -> mono by taking left channel (every other sample)
    if channels == 2 and bit_depth == 16:
        import array
        samples = array.array("h", pcm_bytes)
        mono_samples = samples[::2]  # Take left channel
        pcm_bytes = mono_samples.tobytes()
        channels = 1
        print(f"  [WAV] Converted stereo -> mono")

    print(f"  [WAV] {wav_path.name}: {sample_rate}Hz, {channels}ch, {bit_depth}bit")
    duration = len(pcm_bytes) / (sample_rate * channels * (bit_depth // 8))
    print(f"  [WAV] Duration: {duration:.1f}s ({len(pcm_bytes):,} bytes)")

    return pcm_bytes, sample_rate, channels, bit_depth


def generate_silent_pcm(seconds: float) -> tuple[bytes, int, int, int]:
    """Generate silent PCM audio for testing without a WAV file."""
    num_bytes = int(seconds * STREAM_SAMPLE_RATE * STREAM_CHANNELS * (STREAM_BIT_DEPTH // 8))
    return b"\x00" * num_bytes, STREAM_SAMPLE_RATE, STREAM_CHANNELS, STREAM_BIT_DEPTH


# ---------------------------------------------------------------------------
# Pretty printing
# ---------------------------------------------------------------------------

COLORS = {
    "RESET": "\033[0m",
    "BOLD": "\033[1m",
    "GREEN": "\033[92m",
    "YELLOW": "\033[93m",
    "CYAN": "\033[96m",
    "RED": "\033[91m",
    "DIM": "\033[2m",
}

def c(text: str, color: str) -> str:
    return f"{COLORS.get(color, '')}{text}{COLORS['RESET']}"


def print_interim(data: dict) -> None:
    chunk = data.get("chunk", "?")
    transcript = data.get("transcript", "")
    state = data.get("state", {})

    # Count non-empty sections
    filled = sum(
        1 for v in state.values()
        if isinstance(v, dict) and (v.get("text") or v.get("items"))
    )

    print(f"\n{c('━' * 60, 'DIM')}")
    print(c(f"  CHUNK {chunk} PROCESSED", "BOLD") + c(f"  ({filled}/11 sections filled)", "DIM"))
    if transcript:
        short = transcript[:200] + ("..." if len(transcript) > 200 else "")
        print(c(f"  Transcript: \"{short}\"", "DIM"))

    # Show non-empty sections
    for key, val in state.items():
        if isinstance(val, dict) and val.get("text"):
            label = key.replace("_", " ").title()
            text = val["text"][:100] + ("..." if len(val["text"]) > 100 else "")
            print(f"  {c(label + ':', 'CYAN')} {text}")


def print_final(data: dict) -> None:
    result = data.get("result", {})
    run_id = data.get("run_id", "?")

    print(f"\n{c('=' * 60, 'GREEN')}")
    print(c("  FINAL RESULT", "BOLD"))
    print(c(f"  Run ID: {run_id}", "DIM"))
    print(c("=" * 60, "GREEN"))

    for key, val in result.items():
        label = key.replace("_", " ").title()
        if isinstance(val, dict):
            text = val.get("text", "")
            items = val.get("items", [])
            if text:
                print(f"\n  {c(label, 'BOLD')}")
                print(f"    {text}")
            if items:
                for item in items[:3]:
                    print(f"    • {item}")
                if len(items) > 3:
                    print(c(f"    ... and {len(items) - 3} more", "DIM"))


# ---------------------------------------------------------------------------
# Main streaming coroutine
# ---------------------------------------------------------------------------

async def stream(
    wav_path: Path | None,
    ws_url: str,
    chunk_ms: int,
    speed: float,
) -> None:
    # Load audio
    if wav_path and wav_path.exists():
        print(c(f"\n[STREAM] Loading WAV: {wav_path}", "CYAN"))
        pcm_bytes, sample_rate, channels, bit_depth = read_wav_pcm(wav_path)
    else:
        if wav_path:
            print(c(f"[WARN] WAV not found: {wav_path}. Generating 3 min silent audio.", "YELLOW"))
        else:
            print(c("[INFO] No WAV specified. Generating 3 min silent audio.", "YELLOW"))
        pcm_bytes, sample_rate, channels, bit_depth = generate_silent_pcm(180)

    bytes_per_sample = bit_depth // 8
    bytes_per_second = sample_rate * channels * bytes_per_sample
    frame_bytes = int(chunk_ms / 1000 * bytes_per_second)
    total_frames = len(pcm_bytes) // frame_bytes
    sleep_per_frame = (chunk_ms / 1000) / speed

    total_duration = len(pcm_bytes) / bytes_per_second
    print(c(f"\n[STREAM] Connecting to {ws_url}", "CYAN"))
    print(c(f"[STREAM] Audio: {total_duration:.1f}s | Frame: {chunk_ms}ms | Speed: {speed}x", "DIM"))
    print(c(f"[STREAM] Estimated streaming time: {total_duration / speed:.0f}s", "DIM"))

    start_time = time.monotonic()
    interim_count = 0

    try:
        async with websockets.connect(
            ws_url,
            ping_interval=20,
            ping_timeout=60,
            max_size=10 * 1024 * 1024,
        ) as ws:
            print(c("[STREAM] Connected!", "GREEN"))

            # Stream audio frames in real-time
            for i in range(total_frames):
                frame = pcm_bytes[i * frame_bytes: (i + 1) * frame_bytes]
                await ws.send(frame)

                # Print progress every 10s
                if i % (10_000 // chunk_ms) == 0:
                    sent_secs = i * frame_bytes / bytes_per_second
                    elapsed = time.monotonic() - start_time
                    print(c(
                        f"[STREAM] Sent {sent_secs:.0f}s / {total_duration:.0f}s "
                        f"({sent_secs / total_duration * 100:.0f}%) "
                        f"| Wall time: {elapsed:.0f}s",
                        "DIM"
                    ), end="\r")

                # Drain any incoming messages (non-blocking)
                try:
                    msg = await asyncio.wait_for(ws.recv(), timeout=0.001)
                    data = json.loads(msg)
                    msg_type = data.get("type")
                    if msg_type == "interim":
                        interim_count += 1
                        print_interim(data)
                    elif msg_type == "status":
                        print(c(f"\n[STATUS] {data.get('message')}", "DIM"))
                    elif msg_type == "error":
                        print(c(f"\n[ERROR] {data.get('message')}", "RED"))
                except (asyncio.TimeoutError, asyncio.CancelledError):
                    pass

                await asyncio.sleep(sleep_per_frame)

            print(c(f"\n[STREAM] Audio finished. Sending end signal...", "YELLOW"))
            await ws.send(json.dumps({"type": "end"}))

            # Wait for final result
            print(c("[STREAM] Waiting for final result (up to 5 min)...", "YELLOW"))
            deadline = time.monotonic() + 300

            while time.monotonic() < deadline:
                try:
                    msg = await asyncio.wait_for(ws.recv(), timeout=10.0)
                    data = json.loads(msg)
                    msg_type = data.get("type")

                    if msg_type == "interim":
                        interim_count += 1
                        print_interim(data)
                    elif msg_type == "status":
                        print(c(f"[STATUS] {data.get('message')}", "DIM"))
                    elif msg_type == "error":
                        print(c(f"\n[ERROR] {data.get('message')}", "RED"))
                    elif msg_type == "final":
                        elapsed = time.monotonic() - start_time
                        print_final(data)

                        # Save result JSON
                        ts = datetime.now().strftime("%Y%m%d_%H%M%S")
                        out_path = Path(f"stream_result_{ts}.json")
                        out_path.write_text(
                            json.dumps(data, indent=2, ensure_ascii=False),
                            encoding="utf-8"
                        )
                        print(c(f"\n[DONE] Total time: {elapsed:.1f}s | Chunks: {interim_count}", "GREEN"))
                        print(c(f"[DONE] Result saved to: {out_path.resolve()}", "GREEN"))
                        return

                except asyncio.TimeoutError:
                    print(c("  [waiting for final...]", "DIM"), end="\r")

            print(c("[TIMEOUT] No final message received within 5 minutes.", "RED"))

    except ConnectionRefusedError:
        print(c(f"\n[ERROR] Could not connect to {ws_url}", "RED"))
        print(c("  Is the server running? Start it with:", "DIM"))
        print(c("  conda activate dev && uvicorn app.main:app --host 127.0.0.1 --port 8001", "DIM"))
    except KeyboardInterrupt:
        print(c("\n[INTERRUPTED] Ctrl+C received.", "YELLOW"))


# ---------------------------------------------------------------------------
# CLI
# ---------------------------------------------------------------------------

def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Stream a WAV file to the HIMS WebSocket streaming endpoint.",
        formatter_class=argparse.RawDescriptionHelpFormatter,
    )
    parser.add_argument(
        "--wav",
        type=Path,
        default=DEFAULT_WAV,
        help=f"Path to the WAV file to stream (default: {DEFAULT_WAV})",
    )
    parser.add_argument(
        "--url",
        default=DEFAULT_URL,
        help=f"WebSocket URL (default: {DEFAULT_URL})",
    )
    parser.add_argument(
        "--chunk-ms",
        type=int,
        default=DEFAULT_CHUNK_MS,
        help=f"Audio frame size in ms (default: {DEFAULT_CHUNK_MS})",
    )
    parser.add_argument(
        "--speed",
        type=float,
        default=1.0,
        help="Playback speed multiplier (1.0 = real-time, 2.0 = 2x faster). Default: 1.0",
    )
    return parser.parse_args()


if __name__ == "__main__":
    args = parse_args()
    asyncio.run(stream(
        wav_path=args.wav,
        ws_url=args.url,
        chunk_ms=args.chunk_ms,
        speed=args.speed,
    ))
