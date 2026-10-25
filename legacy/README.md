# Legacy

Superseded code kept for reference. Nothing in the running application imports or calls it.

- `web/api/transcribe.php`: the original local Whisper.cpp transcription endpoint, from before the streaming backend.
- `AI_backend_app/`: earlier single-file versions of `app/models`, `app/prompts`, `app/observing` and `app/utils`
  (each is shadowed by the package of the same name), plus the original `llm_client.py`, now `app/llm/client.py`.
