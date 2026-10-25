# Speech to Text Evaluation

This directory contains scripts, audio files, and evaluation results for testing multiple Speech-to-Text (STT) models. The primary goal is to determine the best model for transcribing clinical/medical audio accurately and quickly.

## Environment Setup (`.env`)

To run the scripts in this directory, you need a `.env` file configured with the required API keys for the respective services. Your `.env` should look something like this:

```env
DEEPGRAM_API_KEY="your_deepgram_api_key"
GROQ_API_KEY="your_groq_api_key"
# NVIDIA API key for Parakeet
API_KEY="your_nvidia_nvcf_api_key" 
# OpenAI API key if running Whisper API
OPENAI_API_KEY="your_openai_api_key"
```
*(Make sure this `.env` file is loaded before executing the Python scripts or activated within your conda environment).*

## Files and Directories

*   **Transcription Scripts:**
    *   `deepgram_stt.py`: Transcribes audio using Deepgram's `nova-3` model.
    *   `groq_stt.py`: Transcribes audio using Whisper (`whisper-large-v3-turbo`) via Groq's high-speed API.
    *   `nvidia.py`: Transcribes audio using NVIDIA's `parakeet-ctc-1.1b-asr` model.
    *   `whisper.py`: Transcribes audio using standard OpenAI Whisper models.
*   **Audio Processing:**
    *   `mp3_to_text.py` & `audio.py`: Helper scripts for handling and transcribing various audio formats.
    *   `mpeg_to_wav_command.txt`: Reference file containing the `ffmpeg` command required to downsample and convert audio to 16kHz Mono WAV (`ffmpeg -i <file_path> -ar 16000 -ac 1 -c:a pcm_s16le <output.wav>`), which is the optimal format for most STT models.
*   **Assets:**
    *   `output.wav` & `ElevenLabs_*.mpeg`: Sample medical audio recordings used as the test inputs.
*   **Directories:**
    *   `ground_truth_script/`: Contains the baseline human-transcribed conversation used to evaluate the accuracy of the models.
    *   `results/`: Contains the raw JSON transcript outputs from each provider, alongside a `results.csv` file summarizing the benchmark scores.

## Evaluation Results Summary

We benchmarked the models by transcribing a telephonic consultation and evaluated them across four metrics: Medical Terminology Accuracy, Formatting, Latency, and Coherence. 

**Overall Rankings:**
1.  🏆 **Deepgram (nova-3):** The overall winner. It offered the best balance of speed (~26s) and medical accuracy, successfully recognizing complex drug names like *Amlodipine* and *Levocetirizine*. It supports 40+ languages.
2.  **Groq (Whisper-large-v3-turbo):** Extremely fast processing and excellent text formatting, but it hallucinated and completely failed on complex medical terminology.
3.  **OpenAI Whisper (Medium):** The most accurate for medical jargon and formatting, but its processing time (~175s) is too slow for real-time production environments.
4.  **NVIDIA (Parakeet-ctc-1.1b):** Fast (~23s), but the lowest performer. It hallucinated words, misspelled drug names, and is restricted mostly to English.

For a full breakdown of the scores out of 10, see [`results/results.csv`](results/results.csv).
