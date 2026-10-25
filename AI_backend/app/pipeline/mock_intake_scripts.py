"""
pipeline/mock_intake_scripts.py — Canned conversations for the mocked AI
outbound intake call (no real telephony/TTS — see IntakeCallSession).

Each script is a list of turns: {"step", "speaker", "text"}.
- step matches the wireframe's "Agent script progress" stepper:
  identity -> consent -> complaint -> history -> red_flags -> meds -> wrapup
- speaker is "Agent" (the AI) or "Patient".

IntakeCallSession pumps one script through the real extraction/red-flag LLM
pipeline at a paced interval — the conversation content is fixed/scripted,
but everything downstream of it (extraction, confidence, red-flag detection)
is genuine, not mocked.
"""
from __future__ import annotations

SCRIPTS: dict[str, list[dict[str, str]]] = {
    # Deliberately includes a chest-pain red-flag moment mid-call, matching the wireframe.
    "chest_pain_flag": [
        {"step": "identity", "speaker": "Agent",
         "text": "Hello, am I speaking with Rekha Sharma? I'm calling from Sunrise Hospital on behalf of Dr. Manan Modi ahead of your visit."},
        {"step": "identity", "speaker": "Patient",
         "text": "Yes, this is Rekha."},
        {"step": "consent", "speaker": "Agent",
         "text": "This call is recorded and the notes are shared only with your doctor. Is that alright?"},
        {"step": "consent", "speaker": "Patient",
         "text": "Yes, that's fine."},
        {"step": "complaint", "speaker": "Agent",
         "text": "Thank you. Can you tell me what's troubling you the most today?"},
        {"step": "complaint", "speaker": "Patient",
         "text": "I've had a tight, heavy pain in my chest for the last three days, mostly in the mornings."},
        {"step": "history", "speaker": "Agent",
         "text": "I'm noting that. Does the pain move anywhere, or come with anything else like sweating or breathlessness?"},
        {"step": "history", "speaker": "Patient",
         "text": "Sometimes it moves to my left arm and I start sweating."},
        {"step": "red_flags", "speaker": "Agent",
         "text": "Okay, this is important — I'm noting this for Dr. Modi right away. Are you able to sit down and rest right now?"},
        {"step": "red_flags", "speaker": "Patient",
         "text": "Yes, I'm sitting down. It's not unbearable, just uncomfortable."},
        {"step": "meds", "speaker": "Agent",
         "text": "Understood. Are you currently taking any regular medication?"},
        {"step": "meds", "speaker": "Patient",
         "text": "Yes, Amlodipine 5 milligrams once a day for my blood pressure."},
        {"step": "meds", "speaker": "Agent",
         "text": "Do you have any allergies to medicines or food?"},
        {"step": "meds", "speaker": "Patient",
         "text": "I'm allergic to Penicillin — it gives me a skin rash."},
        {"step": "meds", "speaker": "Agent",
         "text": "Any past illnesses, surgeries, or family history of heart disease or diabetes I should note?"},
        {"step": "meds", "speaker": "Patient",
         "text": "I was diagnosed with hypertension five years ago. My father has diabetes, and my mother also has high blood pressure like me."},
        {"step": "wrapup", "speaker": "Agent",
         "text": "Thank you, Rekha. I've shared this with Dr. Modi and flagged the chest pain for review before your visit. Please rest and call back immediately if it worsens."},
        {"step": "wrapup", "speaker": "Patient",
         "text": "Okay, thank you."},
    ],
    # Clean run — no red flags, exercises the same pipeline without an escalation.
    "routine_follow_up": [
        {"step": "identity", "speaker": "Agent",
         "text": "Hello, am I speaking with Imran Qureshi? I'm calling from Sunrise Hospital on behalf of Dr. Manan Modi ahead of your visit."},
        {"step": "identity", "speaker": "Patient",
         "text": "Yes, speaking."},
        {"step": "consent", "speaker": "Agent",
         "text": "This call is recorded and shared only with your doctor. Is that alright?"},
        {"step": "consent", "speaker": "Patient",
         "text": "Sure, go ahead."},
        {"step": "complaint", "speaker": "Agent",
         "text": "What's troubling you the most today?"},
        {"step": "complaint", "speaker": "Patient",
         "text": "A burning pain in my upper abdomen for about ten days, worse at night and after spicy food."},
        {"step": "history", "speaker": "Agent",
         "text": "Has anything given you relief, and have you had any vomiting?"},
        {"step": "history", "speaker": "Patient",
         "text": "Antacid syrup gives brief relief. I vomited twice, two days ago."},
        {"step": "red_flags", "speaker": "Agent",
         "text": "Have you noticed any black stools, chest pain, or unexplained weight loss?"},
        {"step": "red_flags", "speaker": "Patient",
         "text": "No, none of that."},
        {"step": "meds", "speaker": "Agent",
         "text": "Are you taking any regular medication, and do you have any allergies?"},
        {"step": "meds", "speaker": "Patient",
         "text": "Pantoprazole 40 milligrams once daily. No known allergies."},
        {"step": "meds", "speaker": "Agent",
         "text": "Any past medical history I should note?"},
        {"step": "meds", "speaker": "Patient",
         "text": "I had an appendectomy in 2019, otherwise nothing significant."},
        {"step": "wrapup", "speaker": "Agent",
         "text": "Thank you. I've shared this with Dr. Modi ahead of your visit. Please avoid spicy food until then."},
        {"step": "wrapup", "speaker": "Patient",
         "text": "Will do, thanks."},
    ],
}

DEFAULT_SCRIPT_KEY = "chest_pain_flag"

# Delay (seconds) the mocked agent "speaks" before the next turn — paced so the
# monitor screen genuinely feels like a live call rather than an instant dump.
TURN_DELAY_SECONDS = 2.0
