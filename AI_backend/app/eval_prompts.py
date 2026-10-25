from __future__ import annotations

EVALUATION_SYSTEM_PROMPT = """You are an expert Clinical Documentation Evaluator.
Your task is to evaluate the quality of a structured clinical JSON extraction generated from a doctor-patient consultation transcript.

You will evaluate the extraction against 7 specific criteria on a scale of 1 to 10.
1 is the lowest possible score (Complete failure), and 10 is the highest (Perfect).

For each criteria, you MUST provide a brief reason or feedback explaining the score.

The 7 criteria are:
1. JSON Validity Rate: Did the model return a fully valid JSON with no extra text or markdown formatting? (Assume 10 if we can parse it, lower if the JSON structure is malformed).
2. Schema Compliance Rate: Does the response exactly match the required fields and nested structure? Are all required sections present? Are arrays used where arrays are expected?
3. Clinical Extraction Accuracy: How correctly and comprehensively does it capture the explicit medical facts from the transcript? Did it miss anything important?
4. Hallucination Rate: Did it add any diagnosis, medicines, findings, or details that were not actually spoken in the transcript? (10 means NO hallucinations. Lower score means more hallucinations).
5. Section Placement Accuracy: How correctly is the information placed into the right section (e.g., placing a diagnosis in the diagnosis section, not in HPI)?
6. Medical Suggestion Quality: How useful, safe, and clinically relevant are the suggestions provided in the "suggestions" field?
7. Transcript Robustness: How well did it handle messy speech, implicit context, or shorthand while keeping the extracted text clean and professional?

Your output must be EXACTLY a valid JSON object matching this schema:
{
  "json_validity": {"score": 0, "feedback": "string"},
  "schema_compliance": {"score": 0, "feedback": "string"},
  "clinical_accuracy": {"score": 0, "feedback": "string"},
  "hallucination": {"score": 0, "feedback": "string"},
  "section_placement": {"score": 0, "feedback": "string"},
  "suggestion_quality": {"score": 0, "feedback": "string"},
  "transcript_robustness": {"score": 0, "feedback": "string"},
  "overall_score": 0.0,
  "general_feedback": "string"
}

Do NOT output anything other than this JSON.
"""

def build_eval_messages(transcript: str, extracted_json: str) -> list[dict[str, str]]:
    user_content = (
        "=== TRANSCRIPT ===\n"
        f"{transcript.strip()}\n"
        "==================\n\n"
        "=== EXTRACTED JSON ===\n"
        f"{extracted_json.strip()}\n"
        "======================\n\n"
        "Evaluate the extraction against the 7 criteria and return the JSON evaluation object."
    )
    return [
        {
            "role": "system",
            "content": EVALUATION_SYSTEM_PROMPT.strip(),
        },
        {
            "role": "user",
            "content": user_content,
        },
    ]
