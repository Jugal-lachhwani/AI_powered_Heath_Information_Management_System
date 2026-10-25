import json
import os
import time
import requests
from dotenv import load_dotenv

load_dotenv()

API_KEY = os.getenv("NVIDIA_API_KEY")

AUDIO_FILE = os.path.join(os.path.dirname(os.path.abspath(__file__)), "output.wav")

URL = "https://1598d209-5e27-4d3c-8079-4751568b1081.invocation.api.nvcf.nvidia.com/v1/audio/transcriptions"

MODEL_NAME = "parakeet-ctc-1.1b-asr"
PROVIDER = "NVIDIA"

headers = {
    "Authorization": f"Bearer {API_KEY}",
}

start = time.perf_counter()

with open(AUDIO_FILE, "rb") as f:
    response = requests.post(
        URL,
        headers=headers,
        files={
            "file": ("output.wav", f, "audio/wav")
        },
        data={
            "language": "en-US",
            "word_time_offsets": "true",
        },
        timeout=300,
    )

end = time.perf_counter()

latency = round(end - start, 3)

print("Status:", response.status_code)
print("Latency:", latency, "seconds")

if response.ok:

    response_json = response.json()

    # Different NVIDIA endpoints may return either "text" or "transcript"
    transcript = (
        response_json.get("text")
        or response_json.get("transcript")
        or response_json.get("result", {}).get("text")
        or ""
    )

    result = {
        "provider": PROVIDER,
        "model": MODEL_NAME,
        "latency_seconds": latency,
        "transcript": transcript
    }

    with open("nvidia_parakeet_results.json", "w") as outfile:
        json.dump(result, outfile, indent=4)

    print(json.dumps(result, indent=4))

else:
    print(response.text)