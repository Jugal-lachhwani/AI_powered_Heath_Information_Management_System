# HIMS AI Backend

FastAPI service that turns live doctor-patient audio into a structured clinical note, and runs the AI
outbound intake-call agent. It is the AI half of [HIMS AI](../README.md); the PHP workspace owns all
persistence and talks to this service only through the browser over WebSocket. This service never touches the database.

## Endpoints

| Endpoint | Purpose |
|---|---|
| `GET /health` | Liveness plus whether the configured LLM is reachable |
| `WS /ws/stream?source=live\|upload` | Consultation: stream PCM audio in, get interim notes and a final validated note back |
| `WS /ws/intake?mode=dynamic\|fixed` | AI intake call (mocked telephony): scripted or LLM-driven agent, extraction and red flags |

### `/ws/stream` protocol

Client to server: binary PCM16 frames at `SAMPLE_RATE`, then `{"type": "end"}`.

Server to client:

```jsonc
{"type": "status",  "message": "..."}
{"type": "interim", "chunk": 2, "transcript": "...", "state": {...}, "transcript_turns": [...]}
{"type": "final",   "run_id": "20260725_125924_6cd5a0", "result": {...}}   // validated ClinicalExtraction
{"type": "error",   "message": "..."}
```

`source=live` uses a persistent Sarvam streaming connection (audio must be paced in real time).
`source=upload` uses batch STT, because an uploaded file arrives much faster than real time.
A real recorded run, including per-chunk patches, is in [`docs/examples/sample_run`](../docs/examples/sample_run).

### `/ws/intake` protocol

In `dynamic` mode the first frame must be
`{"type": "init", "steps": [...], "scenario_text": "...", "patient_name": "..."}`. Control frames then allow
whispering guidance to the agent, putting the call on hold, or taking it over. `fixed` mode plays a canned script
and needs no init frame.

## Setup

```bash
cd AI_backend
python3 -m venv .venv
source .venv/bin/activate          # Windows: .venv\Scripts\activate
pip install -r requirements.txt
cp .env.example .env               # then add your keys
uvicorn app.main:app --host 127.0.0.1 --port 8001 --reload --reload-dir app
```

Needs Python 3.11+ (`audioop-lts` covers 3.13), one LLM key and a Sarvam key for speech-to-text.

## Configuration

All settings come from environment variables (see [`.env.example`](.env.example) and `app/config.py`).

| Variable | Meaning | Default |
|---|---|---|
| `LLM_PROVIDER` | `gemini`, `openai`, `grok`, `nvidia`, `groq`, `ollama` | `ollama` |
| `LLM_MODEL` | Model name for that provider | `qwen2.5:7b` |
| `LLM_TEMPERATURE`, `LLM_TIMEOUT_SECONDS` | Sampling and request timeout | `0`, `360` |
| `SARVAM_API_KEY`, `SARVAM_LANGUAGE`, `SARVAM_MODEL` | STT credentials, BCP-47 language (`hi-IN`, `gu-IN`, `en-IN`), model | `-`, `hi-IN`, `saaras:v3` |
| `CHUNK_SECONDS`, `OVERLAP_SECONDS` | Processing window and overlap | `120`, `10` |
| `SAMPLE_RATE`, `CHANNELS`, `BIT_DEPTH` | Incoming audio format. **Set `SAMPLE_RATE=24000`** to match the browser client's default (`AI_WS_SAMPLE_RATE`) | `16000`, `1`, `16` |
| `CORS_ORIGINS` | Comma-separated browser origins | `*` |
| `RESULTS_DIR` | Where per-run logs go | `AI_backend/.results` |

## Code map

```
app/
├── main.py                  App setup, CORS, router registration (no endpoint logic)
├── config.py                Typed, immutable settings from the environment
├── routes/                  health, ws_stream, ws_intake
├── pipeline/
│   ├── audio_buffer.py      Rolling PCM chunker with overlap
│   ├── stt.py               Sarvam streaming + batch adapters
│   ├── llm_processor.py     Translate/structure, full + delta extraction, interactions, red flags
│   ├── session.py           /ws/stream orchestrator (one per connection)
│   ├── base_intake_session.py        Shared control/extraction/red-flag logic
│   ├── intake_call_session.py        mode=fixed
│   ├── dynamic_intake_session.py     mode=dynamic
│   └── mock_intake_scripts.py        The two canned scripts
├── models/                  ClinicalExtraction, MedicalState, intake schemas (Pydantic)
├── llm/                     Provider-agnostic client + JSON parsing/normalisation
├── prompts/                 Extraction, delta, transcript, interaction and intake prompts
└── observing/               Per-run JSON logging
```

## Testing

```bash
python -m pytest tests/test_chunker.py -v          # unit tests, no network
uvicorn app.main:app --port 8001                    # then, in another shell:
python -m pytest tests/test_ws_stream.py -v -s      # integration, needs a running server
python test_scripts/stream_wav.py --wav consult.wav # replay a WAV like a live mic
python test_scripts/mock_ws_server.py               # canned responses, no LLM/STT spend
python scripts/generate_mock_audio.py               # synthesise a two-voice consultation WAV (needs kokoro)
python test_scripts/stream_dynamic_intake_call.py   # drive an intake call from the CLI
```

## Evaluation

- `test_scripts/compare_gujarati_stt.py` runs Sarvam and Deepgram end to end on the same Gujarati consultation and
  scores each result against `evaluations/ground_truth.json` with an LLM judge (output in `test_scripts/gujarati_comparison/`).
- `app/eval_prompts.py` holds the judge prompt used to score extraction quality; results are in `evaluations/llm_extraction/`.
- `../evaluations/stt_benchmark/` is the earlier STT-engine benchmark.
