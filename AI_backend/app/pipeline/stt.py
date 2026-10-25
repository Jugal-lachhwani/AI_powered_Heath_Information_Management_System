"""
pipeline/stt.py — Speech-to-Text adapter using Sarvam AI.

Design goal: the rest of the pipeline depends only on the interface:
    async def transcribe(pcm_bytes: bytes) -> str

To swap to a different STT provider, replace only this file — no changes
to session.py's pipeline logic beyond what create_stt() is called with.

Switched from Deepgram to Sarvam AI (github.com/sarvamai) for better accuracy
on Indian languages (Hindi, Gujarati, etc.) — Sarvam's DeepgramSTT-equivalent
code is kept below, commented out, for easy reference/rollback.

Sarvam's synchronous REST endpoint only accepts clips under ~30 seconds, while
our pipeline sends full CHUNK_SECONDS (up to 120s) chunks — so SarvamSTT
internally splits each chunk into <=25s sub-segments, transcribes each via a
separate REST call (concurrently), and joins the results. This keeps the
STTAdapter interface (one call per full chunk) unchanged for the rest of the
pipeline, exactly like DeepgramSTT before it.
"""
from __future__ import annotations

import asyncio
try:
    import audioop  # Python <= 3.12
except ModuleNotFoundError:
    import audioop_lts as audioop  # type: ignore[no-redef]  # Python 3.13+
import base64
import contextlib
import logging
from typing import Protocol

import httpx
from sarvamai import AsyncSarvamAI

logger = logging.getLogger(__name__)


# ---------------------------------------------------------------------------
# STT Interface (Protocol for easy swapping)
# ---------------------------------------------------------------------------

class STTAdapter(Protocol):
    async def transcribe(self, pcm_bytes: bytes) -> str:
        """Transcribe raw PCM bytes to text. Returns empty string on failure."""
        ...

    async def close(self) -> None:
        """Clean up any open resources."""
        ...


# ---------------------------------------------------------------------------
# Sarvam AI REST adapter
# ---------------------------------------------------------------------------

class SarvamSTT:
    """
    Calls Sarvam AI's speech-to-text REST API (api.sarvam.ai/speech-to-text).

    Chosen over Deepgram for Indian-language consultations (Hindi, Gujarati,
    etc.) — Sarvam's saaras/saarika models are trained specifically for
    Indian languages. The REST endpoint only guarantees quick responses for
    clips under ~30s, so long chunks are split into sub-segments internally
    (see _split_pcm / MAX_SEGMENT_SECONDS) and transcribed concurrently.

    Audio is sent as a proper self-describing WAV file (not Sarvam's raw
    `input_audio_codec=pcm_raw` option, which requires exactly 16kHz) so the
    real configured sample rate (SAMPLE_RATE in .env, e.g. 24000) is always
    read correctly from the WAV header rather than assumed.
    """

    SARVAM_URL = "https://api.sarvam.ai/speech-to-text"
    MAX_SEGMENT_SECONDS = 25  # stay safely under Sarvam's ~30s REST guidance

    def __init__(
        self,
        api_key: str,
        language: str = "hi-IN",
        model: str = "saaras:v3",
        sample_rate: int = 16000,
        channels: int = 1,
        bit_depth: int = 16,
    ) -> None:
        if not api_key:
            raise ValueError("SARVAM_API_KEY is not set. Cannot use SarvamSTT.")

        self.api_key = api_key
        self.language = language
        self.model = model
        self.sample_rate = sample_rate
        self.channels = channels
        self.bit_depth = bit_depth
        self._client = httpx.AsyncClient(
            timeout=60.0,
            headers={"api-subscription-key": api_key},
        )
        logger.info(
            "SarvamSTT initialized: model=%s language=%s sample_rate=%d",
            model, language, sample_rate,
        )

    async def transcribe(self, pcm_bytes: bytes) -> str:
        """
        Send raw PCM bytes to Sarvam (split into <=30s segments as needed)
        and return the joined transcript text. Returns empty string on error
        (graceful degradation, matches the STTAdapter contract).
        """
        segments = self._split_pcm(pcm_bytes)

        results = await asyncio.gather(
            *(self._transcribe_segment(seg) for seg in segments),
            return_exceptions=True,
        )

        transcripts: list[str] = []
        for result in results:
            if isinstance(result, Exception):
                logger.error("Sarvam segment transcription failed: %s", result)
                continue
            if result:
                transcripts.append(result)

        transcript = " ".join(transcripts).strip()
        logger.info(
            "SarvamSTT: transcribed %d bytes (%d segment(s)) -> %d chars",
            len(pcm_bytes), len(segments), len(transcript),
        )
        return transcript

    def _split_pcm(self, pcm_bytes: bytes) -> list[bytes]:
        """Split raw PCM into <=MAX_SEGMENT_SECONDS chunks, aligned to sample boundaries."""
        bytes_per_sample = self.bit_depth // 8
        bytes_per_second = self.sample_rate * self.channels * bytes_per_sample
        segment_bytes = int(self.MAX_SEGMENT_SECONDS * bytes_per_second)
        segment_bytes -= segment_bytes % max(bytes_per_sample, 1)

        if segment_bytes <= 0 or len(pcm_bytes) <= segment_bytes:
            return [pcm_bytes] if pcm_bytes else []

        return [
            pcm_bytes[i:i + segment_bytes]
            for i in range(0, len(pcm_bytes), segment_bytes)
        ]

    async def _transcribe_segment(self, pcm_segment: bytes) -> str:
        if not pcm_segment:
            return ""

        wav_bytes = _wrap_pcm_in_wav(pcm_segment, self.sample_rate, self.channels, self.bit_depth)
        files = {"file": ("chunk.wav", wav_bytes, "audio/wav")}
        data = {"model": self.model, "language_code": self.language}

        try:
            response = await self._client.post(self.SARVAM_URL, files=files, data=data)
            response.raise_for_status()
            result = response.json()
            return (result.get("transcript") or "").strip()

        except httpx.HTTPStatusError as exc:
            logger.error(
                "Sarvam HTTP error: %s — %s",
                exc.response.status_code,
                exc.response.text[:200],
            )
            return ""
        except Exception as exc:
            logger.exception("Sarvam transcription failed: %s", exc)
            return ""

    async def close(self) -> None:
        await self._client.aclose()


# ---------------------------------------------------------------------------
# Deepgram REST adapter — commented out, replaced by SarvamSTT above.
# Kept for reference / easy rollback (see also DEEPGRAM_* vars in .env).
# ---------------------------------------------------------------------------

# class DeepgramSTT:
#     """
#     Calls Deepgram's pre-recorded audio transcription API.
#
#     Uses the REST endpoint (not the live-streaming SDK) because:
#     - We already have complete 60s chunks before sending to STT
#     - The REST API is simpler and more reliable for batch chunks
#     - Swap to Deepgram live-streaming SDK here if true <100ms latency is needed
#     """
#
#     DEEPGRAM_URL = "https://api.deepgram.com/v1/listen"
#
#     def __init__(self, api_key: str, language: str = "en-IN", model: str = "nova-2") -> None:
#         if not api_key:
#             raise ValueError("DEEPGRAM_API_KEY is not set. Cannot use DeepgramSTT.")
#
#         self.api_key = api_key
#         self.language = language
#         self.model = model
#         self._client = httpx.AsyncClient(
#             timeout=120.0,  # 60s chunk + processing time
#             headers={
#                 "Authorization": f"Token {api_key}",
#                 "Content-Type": "audio/wav",
#             },
#         )
#         logger.info("DeepgramSTT initialized: model=%s language=%s", model, language)
#
#     async def transcribe(self, pcm_bytes: bytes) -> str:
#         """
#         Send raw PCM bytes to Deepgram and return the transcript text.
#         Returns empty string on error (graceful degradation).
#         """
#         # Deepgram needs WAV headers if we're sending raw PCM
#         wav_bytes = _wrap_pcm_in_wav(pcm_bytes)
#
#         params = {
#             "model": self.model,
#             "language": self.language,
#             "smart_format": "true",
#             "punctuate": "true",
#             "diarize": "true",       # Speaker diarization (Doctor / Patient)
#             "utterances": "false",
#         }
#
#         try:
#             response = await self._client.post(
#                 self.DEEPGRAM_URL,
#                 content=wav_bytes,
#                 params=params,
#             )
#             response.raise_for_status()
#             data = response.json()
#             transcript = _extract_transcript(data)
#             logger.info(
#                 "DeepgramSTT: transcribed %d bytes -> %d chars",
#                 len(pcm_bytes), len(transcript),
#             )
#             return transcript
#
#         except httpx.HTTPStatusError as exc:
#             logger.error(
#                 "Deepgram HTTP error: %s — %s",
#                 exc.response.status_code,
#                 exc.response.text[:200],
#             )
#             return ""
#         except Exception as exc:
#             logger.exception("Deepgram transcription failed: %s", exc)
#             return ""
#
#     async def close(self) -> None:
#         await self._client.aclose()
#
#
# def _extract_transcript(data: dict) -> str:
#     """Extract transcript text from Deepgram's response JSON."""
#     try:
#         channels = data["results"]["channels"]
#         alternatives = channels[0]["alternatives"]
#         return alternatives[0].get("transcript", "").strip()
#     except (KeyError, IndexError, TypeError):
#         return ""


# ---------------------------------------------------------------------------
# Sarvam AI real-time streaming adapter (production default)
# ---------------------------------------------------------------------------

class SarvamStreamingSTT:
    """
    Real-time speech-to-text via Sarvam's streaming WebSocket API
    (api.sarvam.ai/speech-to-text/ws, via the official `sarvamai` SDK) —
    transcription only, no translation at this layer (translation happens in
    the transcript-structuring LLM step; see app/prompts/transcript.py).

    Unlike SarvamSTT (REST, one call per full chunk), audio is forwarded to a
    single persistent connection continuously as it arrives (send_audio, one
    call per incoming frame), and the accumulated transcript for a chunk
    window is retrieved on demand (flush_and_get_transcript) — this is what
    makes STT itself real-time rather than "batch after a 120s wait."

    Protocol details below were verified against the real API, not just docs:
    - Per-message audio must be exactly 16kHz (confirmed via a live rejection
      at 24kHz) — so every frame is resampled from settings.sample_rate (e.g.
      24000) down to 16000 with audioop.ratecv, threading `state` across
      calls for glitch-free continuity at buffer boundaries.
    - flush() + recv() returns only the transcript for audio sent since the
      *previous* flush (or connection start) — not cumulative — confirmed by
      flushing two consecutive halves of the same recording and checking the
      second transcript picked up exactly where the first left off. This
      maps directly onto our existing CHUNK_SECONDS boundary with no extra
      bookkeeping needed.
    """

    def __init__(
        self,
        api_key: str,
        language: str = "hi-IN",
        model: str = "saaras:v3",
        sample_rate: int = 16000,
    ) -> None:
        if not api_key:
            raise ValueError("SARVAM_API_KEY is not set. Cannot use SarvamStreamingSTT.")

        self.api_key = api_key
        self.language = language
        self.model = model
        self.sample_rate = sample_rate
        self._client = AsyncSarvamAI(api_subscription_key=api_key)
        self._exit_stack: contextlib.AsyncExitStack | None = None
        self._ws = None
        self._resample_state = None  # threaded across send_audio() calls

        logger.info(
            "SarvamStreamingSTT initialized: model=%s language=%s sample_rate=%d",
            model, language, sample_rate,
        )

    async def connect(self) -> None:
        """Open the persistent streaming connection for this session."""
        self._exit_stack = contextlib.AsyncExitStack()
        self._ws = await self._exit_stack.enter_async_context(
            self._client.speech_to_text_streaming.connect(
                language_code=self.language,
                model=self.model,
            )
        )
        logger.info("SarvamStreamingSTT: connection opened")

    async def send_audio(self, pcm_bytes: bytes) -> None:
        """
        Forward a frame of raw PCM audio (at settings.sample_rate) to the
        streaming connection in real time. Resamples to 16kHz first.
        """
        if self._ws is None or not pcm_bytes:
            return

        try:
            pcm_16k, self._resample_state = audioop.ratecv(
                pcm_bytes, 2, 1, self.sample_rate, 16000, self._resample_state
            )
            await self._ws.transcribe(
                audio=base64.b64encode(pcm_16k).decode(),
                encoding="audio/wav",
                sample_rate=16000,
            )
        except Exception as exc:
            logger.warning("SarvamStreamingSTT: send_audio failed: %s", exc)

    async def flush_and_get_transcript(self, timeout: float = 45.0) -> str:
        """
        Signal end-of-window and return the transcript accumulated since the
        previous flush. Returns "" on timeout/error (never raises — matches
        the graceful-degradation contract of every other STT path).

        `flush()` itself is bounded by this same deadline — if audio arrived
        much faster than real time (e.g. a fast file upload), Sarvam's server
        may still be catching up on a transcription backlog when flush() is
        called, and without a timeout here that await can hang for minutes,
        stalling the whole session until the outer watchdog kills it. Bounding
        it means a single slow window degrades to an empty transcript instead
        of hanging the pipeline.
        """
        if self._ws is None:
            return ""

        deadline = asyncio.get_event_loop().time() + timeout

        try:
            await asyncio.wait_for(self._ws.flush(), timeout=timeout)
        except asyncio.TimeoutError:
            logger.warning("SarvamStreamingSTT: flush() timed out (backlog too large?)")
            return ""
        except Exception as exc:
            logger.warning("SarvamStreamingSTT: flush failed: %s", exc)
            return ""

        while True:
            remaining = deadline - asyncio.get_event_loop().time()
            if remaining <= 0:
                logger.warning("SarvamStreamingSTT: timed out waiting for flushed transcript")
                return ""
            try:
                msg = await asyncio.wait_for(self._ws.recv(), timeout=remaining)
            except (asyncio.TimeoutError, Exception) as exc:
                if isinstance(exc, asyncio.TimeoutError):
                    logger.warning("SarvamStreamingSTT: timed out waiting for flushed transcript")
                else:
                    logger.warning("SarvamStreamingSTT: recv failed: %s", exc)
                return ""

            if msg.type == "data":
                transcript = getattr(msg.data, "transcript", "") or ""
                return transcript.strip()
            if msg.type == "error":
                logger.warning("SarvamStreamingSTT: server error: %s", msg.data)
                return ""
            # "events" (VAD signals) — keep waiting for the actual transcript

    async def close(self) -> None:
        if self._exit_stack is not None:
            try:
                await self._exit_stack.aclose()
            except Exception as exc:
                logger.warning("SarvamStreamingSTT: error closing connection: %s", exc)
        self._ws = None
        self._exit_stack = None


# ---------------------------------------------------------------------------
# Null STT — for testing without an STT API key
# ---------------------------------------------------------------------------

class NullSTT:
    """
    Fallback STT that returns a placeholder transcript.
    Useful for testing the pipeline without a real Sarvam API key.
    Set SARVAM_API_KEY="" and the session will use this.
    """

    async def transcribe(self, pcm_bytes: bytes) -> str:
        logger.warning(
            "NullSTT: No SARVAM_API_KEY set. Returning placeholder transcript. "
            "PCM size: %d bytes", len(pcm_bytes)
        )
        return f"[No STT — {len(pcm_bytes)} bytes of audio received, SARVAM_API_KEY not configured]"

    async def close(self) -> None:
        pass


# ---------------------------------------------------------------------------
# Factory
# ---------------------------------------------------------------------------

def create_stt(
    api_key: str,
    language: str,
    model: str,
    sample_rate: int = 16000,
    channels: int = 1,
    bit_depth: int = 16,
    mode: str = "streaming",
) -> SarvamStreamingSTT | SarvamSTT | NullSTT:
    """
    Return the Sarvam STT adapter matching `mode`, or NullSTT if no API key is set
    (allows the pipeline to run, with placeholder text, even without Sarvam).

    mode="streaming" (default): SarvamStreamingSTT — real-time, for live mic audio,
        which arrives paced at roughly real-time speed so the streaming engine can
        keep up.
    mode="batch": SarvamSTT — REST, for already-recorded file uploads. Uploaded
        audio is sent to this backend far faster than real time (whole file in
        seconds), which the streaming API cannot transcribe correctly — flush()
        would either race ahead of ingestion or block for minutes waiting for a
        huge backlog to be processed. Batch REST has no such pacing requirement.
    """
    if not api_key:
        logger.warning("SARVAM_API_KEY not set — using NullSTT (no real transcription)")
        return NullSTT()

    if mode == "batch":
        return SarvamSTT(
            api_key=api_key,
            language=language,
            model=model,
            sample_rate=sample_rate,
            channels=channels,
            bit_depth=bit_depth,
        )
    return SarvamStreamingSTT(
        api_key=api_key,
        language=language,
        model=model,
        sample_rate=sample_rate,
    )

    # --- Deepgram factory logic, commented out, replaced by SarvamSTT above ---
    # if api_key:
    #     return DeepgramSTT(api_key=api_key, language=language, model=model)
    # logger.warning("DEEPGRAM_API_KEY not set — using NullSTT (no real transcription)")
    # return NullSTT()


# ---------------------------------------------------------------------------
# WAV header helper (shared)
# ---------------------------------------------------------------------------

def _wrap_pcm_in_wav(
    pcm_bytes: bytes,
    sample_rate: int = 16000,
    channels: int = 1,
    bit_depth: int = 16,
) -> bytes:
    """Wrap raw PCM bytes with a minimal WAV header so the STT provider can parse them."""
    import struct

    data_size = len(pcm_bytes)
    byte_rate = sample_rate * channels * (bit_depth // 8)
    block_align = channels * (bit_depth // 8)

    header = struct.pack(
        "<4sI4s4sIHHIIHH4sI",
        b"RIFF",
        36 + data_size,     # ChunkSize
        b"WAVE",
        b"fmt ",
        16,                  # Subchunk1Size (PCM)
        1,                   # AudioFormat (PCM = 1)
        channels,
        sample_rate,
        byte_rate,
        block_align,
        bit_depth,
        b"data",
        data_size,
    )
    return header + pcm_bytes
