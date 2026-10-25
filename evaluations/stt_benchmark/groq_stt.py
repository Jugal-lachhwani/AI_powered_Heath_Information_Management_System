import os
import json
import time
from groq import Groq
from dotenv import load_dotenv

# Load environment variables
load_dotenv()

# Initialize Groq client
client = Groq(
    api_key=os.getenv("GROQ_API_KEY")
)

# Path to your audio file
AUDIO_FILE = os.path.join(os.path.dirname(os.path.abspath(__file__)), "output.wav")

start = time.perf_counter()

with open(AUDIO_FILE, "rb") as audio:
    transcription = client.audio.transcriptions.create(
        file=audio,
        model="whisper-large-v3-turbo",   # or "whisper-large-v3"
        # response_format="verbose_json",
        temperature=0,
        # Leave language unset for automatic language detection
        # timestamp_granularities=["word", "segment"],
    )

latency = time.perf_counter() - start

print("=" * 80)
print(f"Latency: {latency:.3f} sec")
print("=" * 80)

# Print transcript
print("\nTranscript:\n")
print(transcription.text)

print("\nFull Response:\n")
print(json.dumps(transcription.model_dump(), indent=4))