import os
import requests
from dotenv import load_dotenv

load_dotenv()

API_KEY = os.getenv("NVIDIA_API_KEY")

AUDIO_FILE = os.path.join(os.path.dirname(os.path.abspath(__file__)), "ElevenLabs_2026-07-22T04_16_35_Roger - Laid-Back, Casual, Resonant_pre_s50_m2.mp3.mpeg")

url = "https://1598d209-5e27-4d3c-8079-4751568b1081.invocation.api.nvcf.nvidia.com/v1/audio/transcriptions"

headers = {
    "Authorization": f"Bearer {API_KEY}",
}

with open(AUDIO_FILE, "rb") as f:
    files = {
        "file": ("audio.mp3", f, "audio/mpeg")
    }

    data = {
        "language": "en-US",
        "word_time_offsets": "true",
    }

    response = requests.post(
        url,
        headers=headers,
        files=files,
        data=data,
        timeout=300,
    )

print(response.status_code)
print(response.text)