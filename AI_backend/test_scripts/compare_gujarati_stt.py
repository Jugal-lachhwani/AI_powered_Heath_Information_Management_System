#!/usr/bin/env python3
"""
test_scripts/compare_gujarati_stt.py — Sarvam vs Deepgram, head-to-head, on Gujarati audio.

Guj_sample.wav (repo root) is a Gujarati-language recording of the exact same
consultation script as mock_consultation.wav / ground_truth.json (verified by
transcribing the opening seconds before writing this script) — so both
providers' end-to-end output (STT -> clinical JSON) can be scored directly
against ground_truth.json.

Pipeline per provider:
  1. STT the full audio to a transcript.
     - Sarvam: saaras:v3, mode=translate — Gujarati audio -> English text directly
       (this is being evaluated as a real capability, not worked around).
     - Deepgram: general nova-3 model (nova-3-medical doesn't support Gujarati),
       language=gu — native Gujarati transcript (Deepgram has no built-in
       speech-translation feature, so translation happens in step 2 instead).
  2. One full clinical-extraction LLM call (same system prompt as production)
     -> ClinicalExtraction JSON, explicitly instructed to output English text
     regardless of the transcript's language.
  3. LLM-as-judge: score each candidate JSON against ground_truth.json.

Usage:
    cd AI_backend && source .venv/bin/activate
    python test_scripts/compare_gujarati_stt.py

Output: test_scripts/gujarati_comparison/ (raw transcripts, JSONs, judge
verdicts) and test_scripts/gujarati_comparison/results.csv (summary).
"""
from __future__ import annotations

import asyncio
import json
import os
import sys
import time
import wave
from pathlib import Path

import httpx
from dotenv import load_dotenv

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))
load_dotenv(Path(__file__).resolve().parent.parent / ".env", override=True)

from app.config import settings  # noqa: E402
from app.llm.client import LLMClient, LLMError  # noqa: E402
from app.llm.parser import parse_json_object  # noqa: E402
from app.pipeline.stt import SarvamSTT, _wrap_pcm_in_wav  # noqa: E402
from app.prompts.system import get_system_prompt  # noqa: E402

REPO_ROOT = Path(__file__).resolve().parent.parent.parent
GUJ_WAV = REPO_ROOT / "Guj_sample.wav"
GROUND_TRUTH_PATH = REPO_ROOT / "evaluations" / "ground_truth.json"
OUT_DIR = Path(__file__).resolve().parent / "gujarati_comparison"

DEEPGRAM_API_KEY = os.getenv("DEEPGRAM_API_KEY", "")
DEEPGRAM_URL = "https://api.deepgram.com/v1/listen"

TRANSLATE_INSTRUCTION = (
    "\n\nIMPORTANT: The transcript above may be in a language other than English "
    "(e.g. Gujarati). Regardless of the transcript's language, write every extracted "
    "\"text\"/\"items\"/free-text value in the output JSON in English."
)

JUDGE_PROMPT = """You are a clinical documentation QA reviewer. You will be given a GROUND TRUTH \
structured clinical JSON (the correct extraction for a consultation) and a CANDIDATE JSON \
(produced by an automated pipeline from a different-language recording of the SAME consultation).

Score the CANDIDATE against the GROUND TRUTH on these dimensions, each 0-10:
- medical_accuracy: are the clinical facts (symptoms, vitals, diagnosis, drug names/doses) correct and not hallucinated?
- completeness: how much of the ground truth's information is actually present in the candidate?
- coherence: is the candidate's English text clear, well-formed, and readable?
- ground_truth_match: overall, how closely does the candidate match the ground truth field-by-field?

Return ONLY this JSON object, no markdown, no explanation:
{"medical_accuracy": 0, "completeness": 0, "coherence": 0, "ground_truth_match": 0, "notes": "1-2 sentence summary of the biggest differences"}
"""


def read_pcm(path: Path) -> tuple[bytes, int]:
    with wave.open(str(path), "rb") as wf:
        sr = wf.getframerate()
        pcm = wf.readframes(wf.getnframes())
    return pcm, sr


async def sarvam_translate_full(pcm: bytes, sr: int) -> tuple[str, float]:
    """Full-audio Gujarati->English transcript via Sarvam's translate mode."""
    stt = SarvamSTT(api_key=settings.sarvam_api_key, language="gu-IN", model="saaras:v3", sample_rate=sr)
    segments = stt._split_pcm(pcm)

    async def translate_segment(seg: bytes) -> str:
        if not seg:
            return ""
        wav_bytes = _wrap_pcm_in_wav(seg, sr, 1, 16)
        try:
            resp = await stt._client.post(
                stt.SARVAM_URL,
                files={"file": ("chunk.wav", wav_bytes, "audio/wav")},
                data={"model": "saaras:v3", "language_code": "gu-IN", "mode": "translate"},
            )
            resp.raise_for_status()
            return (resp.json().get("transcript") or "").strip()
        except Exception as exc:
            print(f"  [sarvam] segment failed: {exc}")
            return ""

    start = time.monotonic()
    results = await asyncio.gather(*(translate_segment(s) for s in segments))
    elapsed = time.monotonic() - start
    await stt.close()
    return " ".join(r for r in results if r).strip(), elapsed


async def deepgram_transcribe_full(pcm: bytes, sr: int) -> tuple[str, float]:
    """Full-audio native Gujarati transcript via Deepgram's general nova-3 model."""
    if not DEEPGRAM_API_KEY:
        raise RuntimeError("DEEPGRAM_API_KEY not set in .env — cannot run the Deepgram comparison leg.")

    wav_bytes = _wrap_pcm_in_wav(pcm, sr, 1, 16)
    start = time.monotonic()
    async with httpx.AsyncClient(
        headers={"Authorization": f"Token {DEEPGRAM_API_KEY}", "Content-Type": "audio/wav"},
        timeout=180.0,
    ) as client:
        resp = await client.post(
            DEEPGRAM_URL,
            content=wav_bytes,
            params={"model": "nova-3", "language": "gu", "smart_format": "true", "punctuate": "true"},
        )
        resp.raise_for_status()
        data = resp.json()
    elapsed = time.monotonic() - start

    try:
        transcript = data["results"]["channels"][0]["alternatives"][0].get("transcript", "").strip()
    except (KeyError, IndexError, TypeError):
        transcript = ""
    return transcript, elapsed


async def call_with_retry(coro_fn, *args, retries: int = 4, base_wait: float = 25.0, **kwargs):
    """Retry on Gemini 429s with a growing backoff — the free tier's quota window
    is short-lived in practice even though the error labels it 'PerDay'."""
    for attempt in range(1, retries + 1):
        try:
            return await coro_fn(*args, **kwargs)
        except LLMError as exc:
            if "429" not in str(exc) and "RESOURCE_EXHAUSTED" not in str(exc):
                raise
            if attempt == retries:
                raise
            wait = base_wait * attempt
            print(f"  [rate-limited] attempt {attempt}/{retries} failed, waiting {wait:.0f}s before retry...")
            await asyncio.sleep(wait)


async def extract_clinical_json(client: LLMClient, transcript: str, label: str) -> tuple[dict, dict, float]:
    messages = [
        {"role": "system", "content": get_system_prompt("v1")},
        {
            "role": "user",
            "content": (
                "Doctor consultation transcript begins:\n"
                f"{transcript.strip()}\n"
                "Doctor consultation transcript ends.\n\n"
                "Extract the structured clinical JSON from this transcript only. "
                "Return the JSON object only." + TRANSLATE_INSTRUCTION
            ),
        },
    ]
    start = time.monotonic()
    extraction, metrics = await client.extract_full(messages, label=label)
    elapsed = time.monotonic() - start
    return extraction.model_dump(), metrics, elapsed


async def judge_against_ground_truth(client: LLMClient, ground_truth: dict, candidate: dict, label: str) -> dict:
    user_content = (
        "GROUND TRUTH:\n" + json.dumps(ground_truth, indent=2, ensure_ascii=False) +
        "\n\nCANDIDATE:\n" + json.dumps(candidate, indent=2, ensure_ascii=False) +
        "\n\nReturn only the JSON score object."
    )
    content, _ = await client.call(
        [{"role": "system", "content": JUDGE_PROMPT}, {"role": "user", "content": user_content}],
        label=f"judge_{label}",
    )
    try:
        return parse_json_object(content)
    except Exception as exc:
        print(f"  [judge:{label}] could not parse verdict: {exc}")
        return {"medical_accuracy": None, "completeness": None, "coherence": None, "ground_truth_match": None, "notes": "parse failed"}


async def main() -> None:
    OUT_DIR.mkdir(parents=True, exist_ok=True)
    pcm, sr = read_pcm(GUJ_WAV)
    ground_truth = json.loads(GROUND_TRUTH_PATH.read_text())
    duration_s = len(pcm) / (sr * 2)
    print(f"Guj_sample.wav: {duration_s:.1f}s @ {sr}Hz\n")

    # Gemini's free-tier daily quota (20 req/day, shared across models on this project)
    # is already exhausted today — use Groq for this comparison's LLM calls instead
    # (extraction + judging only; doesn't touch the app's production LLM_PROVIDER=gemini).
    llm_settings = settings.__class__(**{
        **settings.__dict__,
        "llm_provider": "groq",
        "llm_model": "llama-3.3-70b-versatile",
    })
    llm_client = LLMClient(llm_settings)
    results_rows = []

    # ---- Sarvam leg (resumable: reuse a saved transcript if present) ----
    sarvam_transcript_path = OUT_DIR / "sarvam_transcript_en.txt"
    if sarvam_transcript_path.exists():
        sarvam_transcript = sarvam_transcript_path.read_text(encoding="utf-8")
        sarvam_stt_time = 0.0
        print(f"[1/4] Sarvam: reusing saved transcript ({len(sarvam_transcript)} chars)")
    else:
        print("[1/4] Sarvam: transcribing + translating full audio...")
        sarvam_transcript, sarvam_stt_time = await sarvam_translate_full(pcm, sr)
        sarvam_transcript_path.write_text(sarvam_transcript, encoding="utf-8")
        print(f"  -> {len(sarvam_transcript)} chars in {sarvam_stt_time:.1f}s")

    sarvam_result_path = OUT_DIR / "sarvam_result.json"
    if sarvam_result_path.exists():
        sarvam_json = json.loads(sarvam_result_path.read_text())
        sarvam_llm_time = 0.0
        print("[2/4] Sarvam: reusing saved clinical JSON")
    else:
        print("[2/4] Sarvam: extracting clinical JSON...")
        sarvam_json, sarvam_llm_metrics, sarvam_llm_time = await call_with_retry(
            extract_clinical_json, llm_client, sarvam_transcript, "sarvam_gu_extraction"
        )
        sarvam_result_path.write_text(json.dumps(sarvam_json, indent=2, ensure_ascii=False), encoding="utf-8")

    # ---- Deepgram leg (resumable) ----
    deepgram_transcript_path = OUT_DIR / "deepgram_transcript_gu.txt"
    if deepgram_transcript_path.exists():
        deepgram_transcript = deepgram_transcript_path.read_text(encoding="utf-8")
        deepgram_stt_time = 0.0
        print(f"[3/4] Deepgram: reusing saved transcript ({len(deepgram_transcript)} chars)")
    else:
        print("[3/4] Deepgram: transcribing full audio (native Gujarati)...")
        deepgram_transcript, deepgram_stt_time = await deepgram_transcribe_full(pcm, sr)
        deepgram_transcript_path.write_text(deepgram_transcript, encoding="utf-8")
        print(f"  -> {len(deepgram_transcript)} chars in {deepgram_stt_time:.1f}s")

    deepgram_result_path = OUT_DIR / "deepgram_result.json"
    if deepgram_result_path.exists():
        deepgram_json = json.loads(deepgram_result_path.read_text())
        deepgram_llm_time = 0.0
        print("[4/4] Deepgram: reusing saved clinical JSON")
    else:
        print("[4/4] Deepgram: extracting clinical JSON (with translate instruction)...")
        deepgram_json, deepgram_llm_metrics, deepgram_llm_time = await call_with_retry(
            extract_clinical_json, llm_client, deepgram_transcript, "deepgram_gu_extraction"
        )
        deepgram_result_path.write_text(json.dumps(deepgram_json, indent=2, ensure_ascii=False), encoding="utf-8")

    # ---- Judge both against ground truth ----
    print("\nScoring both against ground_truth.json...")
    sarvam_scores = await call_with_retry(judge_against_ground_truth, llm_client, ground_truth, sarvam_json, "sarvam")
    await asyncio.sleep(8)
    deepgram_scores = await call_with_retry(judge_against_ground_truth, llm_client, ground_truth, deepgram_json, "deepgram")

    (OUT_DIR / "judge_verdicts.json").write_text(
        json.dumps({"sarvam": sarvam_scores, "deepgram": deepgram_scores}, indent=2, ensure_ascii=False),
        encoding="utf-8",
    )

    # ---- CSV summary (same rubric style as speech_to_text/results/results.csv) ----
    import csv

    def total10(scores: dict) -> float:
        vals = [scores.get(k) for k in ("medical_accuracy", "completeness", "coherence", "ground_truth_match")]
        vals = [v for v in vals if isinstance(v, (int, float))]
        return round(sum(vals) / len(vals), 1) if vals else 0.0

    csv_path = OUT_DIR / "results.csv"
    with open(csv_path, "w", newline="", encoding="utf-8") as f:
        writer = csv.writer(f)
        writer.writerow([
            "Provider", "STT Time (s)", "LLM Extraction Time (s)", "Total Time (s)",
            "Medical Accuracy (/10)", "Completeness (/10)", "Coherence (/10)",
            "Ground Truth Match (/10)", "Average Score (/10)", "Notes",
        ])
        writer.writerow([
            "Sarvam (saaras:v3, translate mode)",
            round(sarvam_stt_time, 1), round(sarvam_llm_time, 1), round(sarvam_stt_time + sarvam_llm_time, 1),
            sarvam_scores.get("medical_accuracy"), sarvam_scores.get("completeness"),
            sarvam_scores.get("coherence"), sarvam_scores.get("ground_truth_match"),
            total10(sarvam_scores), sarvam_scores.get("notes", ""),
        ])
        writer.writerow([
            "Deepgram (nova-3 general, gu + LLM translation)",
            round(deepgram_stt_time, 1), round(deepgram_llm_time, 1), round(deepgram_stt_time + deepgram_llm_time, 1),
            deepgram_scores.get("medical_accuracy"), deepgram_scores.get("completeness"),
            deepgram_scores.get("coherence"), deepgram_scores.get("ground_truth_match"),
            total10(deepgram_scores), deepgram_scores.get("notes", ""),
        ])

    print(f"\nDone. Results written to {OUT_DIR}/ (see results.csv for the summary).")


if __name__ == "__main__":
    asyncio.run(main())
