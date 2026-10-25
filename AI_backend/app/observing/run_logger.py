"""
observing/run_logger.py — Structured per-run result logger.

Creates a folder at results/<run_id>/ and writes:
  - run_meta.json         : metadata snapshot at session start
  - chunk_NN_transcript.txt : transcript text for each chunk
  - chunk_NN_patch.json   : the LLM delta patch
  - chunk_NN_state.json   : full canonical state after this chunk
  - chunk_NN_internal.json: full internal state with provenance (evidence, confidence)
  - final.json            : the final ClinicalExtraction output

All writes are synchronous and wrapped in try/except to never crash the pipeline.
"""
from __future__ import annotations

import json
import logging
from datetime import datetime
from pathlib import Path
from typing import Any

logger = logging.getLogger(__name__)


class RunLogger:
    """
    One instance per WebSocket session.
    Manages the results/<run_id>/ directory.
    """

    def __init__(self, run_id: str, results_dir: Path, metadata: dict[str, Any]) -> None:
        self.run_id = run_id
        self.run_dir = results_dir / run_id
        self._transcripts: list[str] = []
        self._ensure_dir()
        self._write_meta(metadata)

    # ------------------------------------------------------------------
    # Public API
    # ------------------------------------------------------------------

    def log_chunk(
        self,
        chunk_index: int,
        transcript: str,
        patch: dict[str, Any],
        canonical_state: dict[str, Any],
        internal_state: dict[str, Any],
        metrics: dict[str, Any],
        transcript_turns: list[dict[str, Any]] | None = None,
    ) -> None:
        """Write all files for one completed chunk."""
        nn = f"{chunk_index:02d}"

        self._transcripts.append(transcript)

        self._write_text(f"chunk_{nn}_transcript.txt", transcript)
        self._write_json(f"chunk_{nn}_patch.json", patch)
        self._write_json(f"chunk_{nn}_state.json", canonical_state)
        self._write_json(f"chunk_{nn}_internal.json", {
            "chunk": chunk_index,
            "metrics": metrics,
            "state": internal_state,
        })
        if transcript_turns is not None:
            self._write_json(f"chunk_{nn}_transcript_turns.json", transcript_turns)

        logger.info("[run=%s] Logged chunk %d", self.run_id, chunk_index)

    def log_final(self, final_output: dict[str, Any]) -> None:
        """Write the final extraction result and merged transcript."""
        # Write merged transcript
        merged_transcript = "\n\n".join(t for t in self._transcripts if t.strip())
        self._write_text("final_transcript.txt", merged_transcript)

        # Write final JSON
        self._write_json("final.json", {
            "run_id": self.run_id,
            "timestamp": datetime.now().isoformat(),
            "result": final_output,
        })
        logger.info("[run=%s] Final result and transcript written to %s", self.run_id, self.run_dir)

    def log_error(self, error_message: str) -> None:
        """Write an error log entry."""
        self._write_json("error.json", {
            "run_id": self.run_id,
            "timestamp": datetime.now().isoformat(),
            "error": error_message,
        })
        logger.error("[run=%s] Error logged: %s", self.run_id, error_message)

    # ------------------------------------------------------------------
    # Private helpers
    # ------------------------------------------------------------------

    def _ensure_dir(self) -> None:
        try:
            self.run_dir.mkdir(parents=True, exist_ok=True)
        except Exception as e:
            logger.error("Could not create run directory %s: %s", self.run_dir, e)

    def _write_meta(self, metadata: dict[str, Any]) -> None:
        self._write_json("run_meta.json", {
            "run_id": self.run_id,
            "started_at": datetime.now().isoformat(),
            **metadata,
        })

    def _write_json(self, filename: str, data: Any) -> None:
        try:
            path = self.run_dir / filename
            path.write_text(json.dumps(data, indent=2, ensure_ascii=False), encoding="utf-8")
        except Exception as e:
            logger.error("[run=%s] Failed to write %s: %s", self.run_id, filename, e)

    def _write_text(self, filename: str, text: str) -> None:
        try:
            path = self.run_dir / filename
            path.write_text(text, encoding="utf-8")
        except Exception as e:
            logger.error("[run=%s] Failed to write %s: %s", self.run_id, filename, e)


# ---------------------------------------------------------------------------
# Factory — generate a run_id from the current datetime
# ---------------------------------------------------------------------------

def make_run_id(prefix: str = "") -> str:
    """Generate a unique run_id based on datetime. e.g. '20260724_163000_a3f2'"""
    import uuid
    ts = datetime.now().strftime("%Y%m%d_%H%M%S")
    uid = uuid.uuid4().hex[:6]
    parts = [p for p in [prefix, ts, uid] if p]
    return "_".join(parts)
