import os
import json
import time
from dotenv import load_dotenv
from deepgram import DeepgramClient

load_dotenv()

client = DeepgramClient(
    api_key=os.getenv("DEEPGRAM_API_KEY")
)

AUDIO_FILE = os.path.join(os.path.dirname(os.path.abspath(__file__)), "output.wav")

with open(AUDIO_FILE, "rb") as f:
    audio = f.read()

start = time.perf_counter()

response = client.listen.v1.media.transcribe_file(
    request=audio,
    model="nova-3",
    smart_format=True,
    punctuate=True,
    diarize=True,
    detect_language=True,
)

latency = time.perf_counter() - start

print("=" * 80)
print(f"Latency : {latency:.3f} sec")
print("=" * 80)

print(response.results.channels[0].alternatives[0].transcript)

with open("deepgram_output.json", "w") as f:
    f.write(response.model_dump_json(indent=4))

print("Saved to deepgram_output.json")