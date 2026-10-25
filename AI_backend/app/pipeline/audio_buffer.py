"""
pipeline/audio_buffer.py — Rolling PCM audio chunker.

Framework-agnostic. No dependency on Deepgram, FastAPI, or the LLM.
Can receive audio from:
  - WebSocket binary frames
  - A WAV file simulator (reading chunks in real-time)
  - A real microphone stream

Implements rolling windowing with overlap:
  Chunk 1: bytes[0   : CHUNK_BYTES]
  Chunk 2: bytes[CHUNK_BYTES - OVERLAP_BYTES : 2*CHUNK_BYTES - OVERLAP_BYTES]
  ...

The overlap bytes are kept in the buffer as context for the next chunk.
"""
from __future__ import annotations

import logging
from collections import deque
from typing import Generator

logger = logging.getLogger(__name__)


class AudioBuffer:
    """
    Accumulates raw PCM bytes and yields complete chunks with overlap.

    Args:
        chunk_seconds:   Duration of each output chunk (e.g. 60).
        overlap_seconds: How many seconds to repeat at the start of each chunk (e.g. 10).
        sample_rate:     Sample rate in Hz (e.g. 16000).
        channels:        Number of audio channels (1 = mono).
        bit_depth:       Bits per sample (16 = PCM16 = 2 bytes per sample).
    """

    def __init__(
        self,
        chunk_seconds: int = 60,
        overlap_seconds: int = 10,
        sample_rate: int = 16000,
        channels: int = 1,
        bit_depth: int = 16,
    ) -> None:
        if overlap_seconds >= chunk_seconds:
            raise ValueError("overlap_seconds must be less than chunk_seconds")

        self.chunk_seconds = chunk_seconds
        self.overlap_seconds = overlap_seconds
        self.sample_rate = sample_rate
        self.channels = channels
        self.bytes_per_sample = bit_depth // 8

        # Precompute byte sizes
        self._bytes_per_second = sample_rate * channels * self.bytes_per_sample
        self._chunk_bytes = chunk_seconds * self._bytes_per_second
        self._overlap_bytes = overlap_seconds * self._bytes_per_second

        # Internal byte buffer
        self._buffer = bytearray()
        self._chunk_count = 0

        logger.info(
            "AudioBuffer initialized: chunk=%ds overlap=%ds "
            "chunk_bytes=%d overlap_bytes=%d",
            chunk_seconds, overlap_seconds,
            self._chunk_bytes, self._overlap_bytes,
        )

    # ------------------------------------------------------------------
    # Public API
    # ------------------------------------------------------------------

    def feed(self, data: bytes) -> Generator[tuple[int, bytes], None, None]:
        """
        Feed raw PCM bytes into the buffer.

        Yields (chunk_index, chunk_bytes) for every complete chunk produced.
        chunk_index is 1-based.
        """
        self._buffer.extend(data)

        while len(self._buffer) >= self._chunk_bytes:
            chunk_data = bytes(self._buffer[: self._chunk_bytes])
            self._chunk_count += 1
            logger.info(
                "Chunk %d ready: %d bytes (%.1fs of audio)",
                self._chunk_count,
                len(chunk_data),
                len(chunk_data) / self._bytes_per_second,
            )
            yield self._chunk_count, chunk_data

            # Roll: discard everything except the overlap tail
            self._buffer = self._buffer[self._chunk_bytes - self._overlap_bytes:]

    def flush(self) -> tuple[int, bytes] | None:
        """
        Flush any remaining bytes in the buffer as a final partial chunk.
        Returns (chunk_index, chunk_bytes) or None if buffer is empty.
        Call this when the audio stream ends.
        """
        if not self._buffer:
            return None

        self._chunk_count += 1
        chunk_data = bytes(self._buffer)
        self._buffer.clear()

        logger.info(
            "Flushing final partial chunk %d: %d bytes (%.1fs of audio)",
            self._chunk_count,
            len(chunk_data),
            len(chunk_data) / self._bytes_per_second,
        )
        return self._chunk_count, chunk_data

    def reset(self) -> None:
        """Reset the buffer state (for reuse across sessions)."""
        self._buffer.clear()
        self._chunk_count = 0

    @property
    def chunk_count(self) -> int:
        return self._chunk_count

    @property
    def buffered_bytes(self) -> int:
        return len(self._buffer)

    @property
    def buffered_seconds(self) -> float:
        return len(self._buffer) / self._bytes_per_second

    def __repr__(self) -> str:
        return (
            f"AudioBuffer(chunk={self.chunk_seconds}s, overlap={self.overlap_seconds}s, "
            f"buffered={self.buffered_seconds:.1f}s, chunks_emitted={self._chunk_count})"
        )
