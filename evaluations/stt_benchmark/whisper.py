import os
import json
import time
from pathlib import Path

from faster_whisper import WhisperModel

# ------------------------------------
# Configuration
# ------------------------------------

AUDIO_FILE = os.path.join(os.path.dirname(os.path.abspath(__file__)), "output.wav")

MODELS = [
    "small",
    "medium"
]

DEVICE = "cpu"
COMPUTE_TYPE = "int8"      # Best for CPU benchmarking

OUTPUT_FILE = "whisper_results.json"

# ------------------------------------

results = {}

for model_name in MODELS:

    print(f"\nLoading {model_name}...")

    model = WhisperModel(
        model_name,
        device=DEVICE,
        compute_type=COMPUTE_TYPE,
    )

    start = time.perf_counter()

    segments, info = model.transcribe(
        AUDIO_FILE,
        beam_size=5,
    )

    transcript = " ".join(
        segment.text.strip()
        for segment in segments
    )

    latency = time.perf_counter() - start

    results[model_name] = {
        "model": model_name,
        "language": info.language,
        "language_probability": round(info.language_probability, 4),
        "latency_seconds": round(latency, 3),
        "transcript": transcript,
    }

print("\n\n==========================")
print(json.dumps(results, indent=4))

with open(OUTPUT_FILE, "w") as f:
    json.dump(results, f, indent=4)

print(f"\nSaved to {OUTPUT_FILE}")