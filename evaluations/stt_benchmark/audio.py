import sounddevice as sd
from scipy.io.wavfile import write

duration = 5  # seconds
sample_rate = 16000

print("Speak now...")
audio = sd.rec(
    int(duration * sample_rate),
    samplerate=sample_rate,
    channels=1,
    dtype="int16"
)
sd.wait()

write("sample.wav", sample_rate, audio)

print("Saved as sample.wav")