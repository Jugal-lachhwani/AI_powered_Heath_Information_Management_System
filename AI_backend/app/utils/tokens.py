"""
utils/tokens.py — Token estimation helper.
"""
from __future__ import annotations


def estimate_tokens(text: str) -> int:
    """
    Rough token count estimate: ~4 characters per token.
    Good enough for latency metrics; not used for billing.
    """
    return max(1, len(text) // 4)
