def estimate_tokens(text: str) -> int:
    """
    Estimates the number of tokens in a given text using a character-based estimation.
    The industry standard heuristic for English text is roughly 1 token per 4 characters.
    """
    if not text:
        return 0
    return max(1, len(text) // 4)
