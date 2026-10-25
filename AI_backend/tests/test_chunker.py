"""
tests/test_chunker.py — Unit tests for AudioBuffer rolling logic.

Tests the chunking without any STT, LLM, or network dependency.
Run with: python -m pytest tests/test_chunker.py -v
"""
import pytest
from app.pipeline.audio_buffer import AudioBuffer


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def make_pcm(seconds: float, sample_rate: int = 16000, channels: int = 1, bit_depth: int = 16) -> bytes:
    """Generate silent PCM bytes for a given duration."""
    num_bytes = int(seconds * sample_rate * channels * (bit_depth // 8))
    return b"\x00" * num_bytes


# ---------------------------------------------------------------------------
# Tests
# ---------------------------------------------------------------------------

class TestAudioBufferBasic:
    def test_init(self):
        buf = AudioBuffer(chunk_seconds=60, overlap_seconds=10, sample_rate=16000)
        assert buf.chunk_seconds == 60
        assert buf.overlap_seconds == 10
        assert buf.buffered_bytes == 0
        assert buf.chunk_count == 0

    def test_overlap_must_be_less_than_chunk(self):
        with pytest.raises(ValueError):
            AudioBuffer(chunk_seconds=10, overlap_seconds=10)

    def test_no_chunk_before_full_window(self):
        buf = AudioBuffer(chunk_seconds=60, overlap_seconds=10, sample_rate=16000)
        # Feed 30s of audio — should produce no chunks
        data = make_pcm(30)
        chunks = list(buf.feed(data))
        assert chunks == []
        assert buf.chunk_count == 0

    def test_one_chunk_at_exactly_60s(self):
        buf = AudioBuffer(chunk_seconds=60, overlap_seconds=10, sample_rate=16000)
        data = make_pcm(60)
        chunks = list(buf.feed(data))
        assert len(chunks) == 1
        idx, chunk_bytes = chunks[0]
        assert idx == 1
        # 60s * 16000 * 1 * 2 bytes = 1,920,000 bytes
        assert len(chunk_bytes) == 60 * 16000 * 2

    def test_chunk_index_is_1_based(self):
        buf = AudioBuffer(chunk_seconds=60, overlap_seconds=10, sample_rate=16000)
        data = make_pcm(60)
        chunks = list(buf.feed(data))
        assert chunks[0][0] == 1


class TestAudioBufferOverlap:
    def test_overlap_retained_after_first_chunk(self):
        """After emitting chunk 1, the buffer should contain exactly overlap_seconds of audio."""
        buf = AudioBuffer(chunk_seconds=60, overlap_seconds=10, sample_rate=16000)
        data = make_pcm(60)
        list(buf.feed(data))  # Consume chunk 1

        # Buffer should now hold 10s of overlap
        expected_overlap_bytes = 10 * 16000 * 2
        assert buf.buffered_bytes == expected_overlap_bytes

    def test_second_chunk_uses_overlap(self):
        """
        Chunk 2 should start with the 10s overlap from chunk 1 and cover 50s of new audio,
        totaling 60s (10 overlap + 50 new).
        """
        buf = AudioBuffer(chunk_seconds=60, overlap_seconds=10, sample_rate=16000)

        # Feed 110s total: chunk 1 is [0-60], chunk 2 is [50-110]
        data = make_pcm(110)
        chunks = list(buf.feed(data))

        assert len(chunks) == 2
        assert chunks[0][0] == 1
        assert chunks[1][0] == 2
        # Both chunks should be exactly 60s of bytes
        expected_chunk_bytes = 60 * 16000 * 2
        assert len(chunks[0][1]) == expected_chunk_bytes
        assert len(chunks[1][1]) == expected_chunk_bytes

    def test_three_chunks(self):
        buf = AudioBuffer(chunk_seconds=60, overlap_seconds=10, sample_rate=16000)
        # 170s = chunk1 [0-60], chunk2 [50-110], chunk3 [100-160], 10s remaining
        data = make_pcm(170)
        chunks = list(buf.feed(data))
        assert len(chunks) == 3
        assert [c[0] for c in chunks] == [1, 2, 3]


class TestAudioBufferFlush:
    def test_flush_returns_remaining_bytes(self):
        buf = AudioBuffer(chunk_seconds=60, overlap_seconds=10, sample_rate=16000)
        # Feed only 30s — no chunks emitted
        list(buf.feed(make_pcm(30)))
        result = buf.flush()
        assert result is not None
        idx, chunk_bytes = result
        assert idx == 1
        assert len(chunk_bytes) == 30 * 16000 * 2

    def test_flush_returns_none_on_empty_buffer(self):
        buf = AudioBuffer(chunk_seconds=60, overlap_seconds=10, sample_rate=16000)
        assert buf.flush() is None

    def test_flush_after_full_chunk(self):
        buf = AudioBuffer(chunk_seconds=60, overlap_seconds=10, sample_rate=16000)
        # Feed 65s: chunk 1 at 60s, then 5s + 10s overlap = 15s remaining
        list(buf.feed(make_pcm(65)))
        result = buf.flush()
        assert result is not None
        assert result[0] == 2  # Second chunk index


class TestAudioBufferStreaming:
    def test_small_incremental_feeds(self):
        """Simulate receiving audio in small 1s frames, like a real WebSocket stream."""
        buf = AudioBuffer(chunk_seconds=60, overlap_seconds=10, sample_rate=16000)
        all_chunks = []

        # Send 65 frames of 1s each
        for _ in range(65):
            frame = make_pcm(1)
            all_chunks.extend(buf.feed(frame))

        assert len(all_chunks) == 1
        assert all_chunks[0][0] == 1

    def test_reset_clears_state(self):
        buf = AudioBuffer(chunk_seconds=60, overlap_seconds=10, sample_rate=16000)
        list(buf.feed(make_pcm(60)))
        assert buf.chunk_count == 1
        buf.reset()
        assert buf.chunk_count == 0
        assert buf.buffered_bytes == 0
