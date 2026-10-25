import os
os.environ["CUDA_VISIBLE_DEVICES"] = ""

import numpy as np
import soundfile as sf
from kokoro import KPipeline

# Voice IDs for Kokoro
VOICES = {
    "Doctor": "am_adam", 
    "Patient": "af_bella"
}

print("Loading Kokoro pipeline on CPU...")
pipeline = KPipeline(lang_code='a', device='cpu')

transcript = """
**Doctor:** Hello? Am I speaking to, um, Mr. ABC? This is Dr. Sharma.
**Patient:** Yeah, yes doctor, it’s ABC here. Sorry, I was just... coughs... my throat is killing me. Good morning.
**Doctor:** Good morning ABC. How can I help you today? What's going on?
**Patient:** Well, it's this fever. And I have a cough, and my whole body just... I have body pain everywhere. I’m just feeling very tired, like, exhausted. It started about three days ago.
**Doctor:** Three days, okay. Was the fever high right from the start?
**Patient:** Oh, no, initially it was mild, but then yesterday evening it shot up. It went up to around 101°F. Oh, and I took some Paracetamol for it. The 650 mg one. Twice yesterday.
**Doctor:** Okay, Paracetamol 650 twice. Did that help?
**Patient:** It reduced the fever for a few hours, yeah, but it came right back. Which reminds me, my temperature right now—I checked it right before you called because, you know, teleconsultation—I have a thermometer and all that stuff at home. It’s 100.8°F right now.
**Doctor:** 100.8. Good that you checked. Do you happen to have a BP machine, pulse oximeter, or weighing scale too?
**Patient:** Yeah, I checked everything! My weight is 74 kilograms. And the oxygen saturation is 98%. Pulse is 92 per minute. Oh, and blood pressure is 132 over 84 mmHg.
**Doctor:** 132 over 84. Have you ever been diagnosed with high blood pressure? Or diabetes, asthma... anything like that?
**Patient:** Hypertension, yeah. They diagnosed me about five years ago. I take Amlodipine for it. 5 mg, once daily. But no asthma or thyroid disease. And no surgeries or previous hospital admissions.
**Doctor:** Okay, continue the Amlodipine. Now, about this cough—is it dry or are you bringing out sputum?
**Patient:** It's mostly dry. I mean, occasionally I cough a little mucus, but not much. But you know what worries me? When I'm climbing stairs, I feel slightly breathless.
**Doctor:** Any chest pain with that?
**Patient:** No chest pain, no.
**Doctor:** Any wheezing or severe shortness of breath?
**Patient:** No, just the slight breathlessness on stairs.
**Doctor:** Loss of smell or taste? Vomiting, diarrhea?
**Patient:** No doctor, none of that. No abdominal pain or burning during urination either. Oh, I should probably mention, one of my office colleagues had a viral fever last week. So I’ve definitely been in contact with someone who was sick.
**Doctor:** I see. That's good to know. And does anyone in your family have diabetes, hypertension, heart disease, or asthma?
**Patient:** My father has diabetes. And my mother has hypertension, just like me.
**Doctor:** Okay. Are you allergic to any medicines or foods?
**Patient:** Penicillin. Yes doctor, I'm allergic to Penicillin. I develop a skin rash after taking it.
**Doctor:** Thank you for informing me, I'll avoid prescribing Penicillin-based medicines. So, just listening to you, you're able to speak comfortably in full sentences. Do you notice any swelling, skin rashes right now, or severe weakness?
**Patient:** No swelling or rashes, no.
**Doctor:** Alright. From your description, you appear conscious, oriented, mildly febrile, and not in respiratory distress. Your respiratory system appears mildly affected with an upper respiratory tract infection. No concerning cardiac, abdominal, or neurological symptoms are present from your history. My provisional diagnosis is Acute Viral Upper Respiratory Tract Infection. Basically, a viral fever with pharyngitis.
**Patient:** Ah, okay. Should I go get a COVID test or an X-ray?
**Doctor:** Since your oxygen level is normal at 98% and your symptoms are mild, we'll start treatment first. However, I'd like you to get investigations only if the fever continues beyond 48 hours or your symptoms worsen.
**Patient:** What kind of investigations?
**Doctor:** A Complete Blood Count (CBC), C-Reactive Protein (CRP), a COVID-19 or Influenza test if indicated, and a Chest X-ray if that breathlessness increases.
**Patient:** Okay, only if it gets worse. What should I take now? More Paracetamol?
**Doctor:** Yes, I'm prescribing Tablet Paracetamol 650 mg. One tablet every 6 hours, but ONLY if the fever exceeds 100°F. Maximum 4 tablets per day. Also, continue your Amlodipine 5 mg once daily for your blood pressure.
**Patient:** Okay, Paracetamol and Amlodipine. Anything for the cough and throat?
**Doctor:** I'm also prescribing Tablet Levocetirizine 5 mg. Take it once daily at night for 5 days. For the throat, do warm saline gargles three to four times daily, and steam inhalation twice daily.
**Patient:** Steam twice a day, got it.
**Doctor:** Make sure you drink plenty of fluids, get adequate rest, and eat soft nutritious food. Wear a mask if you're around family members to reduce transmission.
**Patient:** Okay.
**Doctor:** Now, please listen carefully. If you develop difficulty breathing, persistent high fever above 102°F, oxygen saturation below 94%, severe chest pain, or confusion... visit the nearest emergency department immediately.
**Patient:** ER if oxygen is below 94, fever above 102. Okay doctor. I'll follow all your instructions.
**Doctor:** I'd like to review you after three days, or earlier if your symptoms worsen. Please keep a record of your temperature and oxygen saturation twice daily.
**Patient:** Twice daily records, sure doctor. Thank you very much.
**Doctor:** You're welcome, ABC. Take care, get plenty of rest, and I wish you a speedy recovery.
**Patient:** Thank you doctor. Goodbye.
**Doctor:** Goodbye.
"""

lines = [line.strip() for line in transcript.split('\n') if line.strip()]

audio_pieces = []

for i, line in enumerate(lines):
    if line.startswith("**Doctor:**"):
        text = line.replace("**Doctor:**", "").strip()
        voice = VOICES["Doctor"]
    elif line.startswith("**Patient") or line.startswith("**Patient:**"):
        text = line.split(":**", 1)[-1].strip()
        voice = VOICES["Patient"]
    else:
        continue
        
    print(f"Generating audio for '{text[:30]}...'")
    generator = pipeline(text, voice=voice, speed=1.0, split_pattern=r'\n+')
    for gs, ps, audio in generator:
        if audio is not None:
            audio_pieces.append(audio)

if audio_pieces:
    print("Concatenating and saving audio...")
    final_audio = np.concatenate(audio_pieces)
    output_audio = os.path.join(os.getcwd(), "mock_consultation.wav")
    sf.write(output_audio, final_audio, 24000)
    print(f"Finished! Audio saved to {output_audio}")
