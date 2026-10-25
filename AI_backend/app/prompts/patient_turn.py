"""
prompts/patient_turn.py — Dynamic-mode LLM-simulated patient turn generation.

Plain conversational TEXT generation (json_mode=False), not JSON extraction.
Used by DynamicIntakeCallSession to role-play the patient's side of the call,
given a short scenario description (what's actually wrong with them) so the
conversation stays consistent turn to turn instead of contradicting itself.
"""
from __future__ import annotations

import json


PATIENT_TURN_SYSTEM_PROMPT = """You are role-playing a PATIENT on a phone intake call with a hospital's AI assistant, for a testing/demo tool. You are NOT the AI assistant — you answer as the patient would.

You will receive a SCENARIO (a short description of what's actually wrong with this
patient — treat it as ground truth about your own symptoms/history) and the
CALL_TRANSCRIPT_SO_FAR. The AI assistant just asked you something (the most recent
"Agent" turn in the transcript).

==================================================
RULES
==================================================

1. Answer consistently with SCENARIO and with anything you've already said earlier
   in CALL_TRANSCRIPT_SO_FAR — never contradict yourself.
2. Only reveal details relevant to what was just asked. Don't volunteer your entire
   scenario in one answer — real patients answer the question asked.
3. Sound like a real person on a phone call: natural, occasionally hesitant, plain
   language — not a clinical summary.
4. Keep it to 1-3 sentences.
5. If SCENARIO doesn't cover something you're asked (e.g. an allergy that was never
   mentioned), give a plausible ordinary answer (e.g. "No, none that I know of.").

Output ONLY the words you would say, nothing else. No markdown, no quotes, no
explanation, no stage directions.
"""


def build_patient_turn_messages(
    scenario_text: str,
    transcript_so_far: list[dict],
    agent_question: str,
) -> list[dict[str, str]]:
    transcript_json = json.dumps(transcript_so_far, indent=2, ensure_ascii=False)
    user_content = (
        "SCENARIO (ground truth about your symptoms/history):\n"
        + (scenario_text.strip() or "No specific scenario given — answer as a generally healthy patient with mild, non-specific symptoms.")
        + "\n\nCALL_TRANSCRIPT_SO_FAR:\n"
        + transcript_json
        + "\n\nThe assistant just asked:\n"
        + agent_question.strip()
        + "\n\nAnswer as the patient now."
    )
    return [
        {"role": "system", "content": PATIENT_TURN_SYSTEM_PROMPT.strip()},
        {"role": "user", "content": user_content},
    ]
