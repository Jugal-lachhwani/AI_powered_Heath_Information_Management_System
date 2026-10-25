# Evaluations

| Folder | What it holds |
|---|---|
| `stt_benchmark/` | Scripts and raw results comparing Deepgram, Groq Whisper, Whisper and NVIDIA Parakeet on a clinical call |
| `llm_extraction/` | Human and LLM-judge scores for candidate extraction models on a streamed consultation, and the models tried |
| `ground_truth.json` | Hand-written reference note used by the judges (also used by `AI_backend/test_scripts/compare_gujarati_stt.py`) |

The Sarvam vs Deepgram Gujarati comparison lives next to the backend code in `AI_backend/test_scripts/gujarati_comparison/`.
