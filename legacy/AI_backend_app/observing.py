import json
import logging
import uuid
from datetime import datetime
from pathlib import Path
import os

from .config import settings


logger = logging.getLogger(__name__)

OBSERVING_DIR = Path(__file__).resolve().parent.parent / "new_observing"

def save_observation(transcript: str, extraction_json: str, metrics: dict = None) -> None:
    try:
        OBSERVING_DIR.mkdir(parents=True, exist_ok=True)
        timestamp = datetime.now().isoformat(timespec="seconds").replace(":", "-")
        unique_id = uuid.uuid4().hex[:8]
        
        filename = f"obs_{timestamp}_{unique_id}.json"
        filepath = OBSERVING_DIR / filename
        
        data = {
            "timestamp": timestamp,
            "provider": settings.llm_provider,
            "model": settings.llm_model,
            "metrics": metrics or {},
            "transcript": transcript,
            "extraction": json.loads(extraction_json),
        }
        
        filepath.write_text(json.dumps(data, indent=2), encoding="utf-8")
        logger.info(f"Saved observation to {filepath}")
    except Exception as e:
        logger.error(f"Failed to save observation: {e}")

