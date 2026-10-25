<?php
/**
 * scripts/seed_demo.php — populate a fresh database with fictional demo data.
 *
 * Lets you explore the whole UI (dashboard, review screen, analytics, timeline,
 * a saved consultation) without any LLM or STT API keys. Every name, number and
 * transcript below is invented.
 *
 *   php scripts/seed_demo.php
 *
 * Refuses to run if the database already has doctor accounts.
 * Demo logins:  anita.sharma@example.com / demo1234  (admin)
 *               rohan.mehta@example.com  / demo1234  (doctor)
 */
if (PHP_SAPI !== 'cli') {
    exit("Run this from the command line.\n");
}

require __DIR__ . '/../includes/core/db.php';

if ((int) $pdo->query('SELECT COUNT(*) FROM doctors')->fetchColumn() > 0) {
    exit("Database already has doctors; refusing to seed. Delete web/data/hims.sqlite first.\n");
}

$pdo->beginTransaction();

// ---------------------------------------------------------------- doctors
$insertDoctor = $pdo->prepare('INSERT INTO doctors (full_name, email, password_hash, role) VALUES (?, ?, ?, ?)');
$hash = password_hash('demo1234', PASSWORD_DEFAULT);
$insertDoctor->execute(['Anita Sharma', 'anita.sharma@example.com', $hash, 'admin']);
$anita = (int) $pdo->lastInsertId();
$insertDoctor->execute(['Rohan Mehta', 'rohan.mehta@example.com', $hash, 'doctor']);
$rohan = (int) $pdo->lastInsertId();

// --------------------------------------------------------------- patients
$patients = [];
$insertPatient = $pdo->prepare("INSERT INTO patients (uhid, name, age, gender, created_by_doctor_id) VALUES ('', ?, ?, ?, ?)");
foreach ([
    'rahul'    => ['Rahul Verma', 46, 'Male'],
    'mohammed' => ['Mohammed Khan', 58, 'Male'],
    'sneha'    => ['Sneha Patel', 34, 'Female'],
    'priya'    => ['Priya Nair', 29, 'Female'],
    'arjun'    => ['Arjun Desai', 51, 'Male'],
    'kavita'   => ['Kavita Joshi', 42, 'Female'],
] as $key => [$name, $age, $gender]) {
    $insertPatient->execute([$name, $age, $gender, $anita]);
    $id = (int) $pdo->lastInsertId();
    generate_uhid($pdo, $id);
    $patients[$key] = $id;
}

// ----------------------------------------- a saved consultation (real run)
$run = json_decode(file_get_contents(__DIR__ . '/../../docs/examples/sample_run/final.json'), true);
$visitId = start_visit_for_patient($pdo, $patients['rahul'], $anita);
$pdo->prepare('INSERT INTO consultations (visit_id, patient_id, doctor_id, status, data) VALUES (?, ?, ?, ?, ?)')
    ->execute([$visitId, $patients['rahul'], $anita, 'final', json_encode($run['result'])]);

// ----------------------------------------------------------- intake calls
function sec(string $text, array $items, float $confidence): array
{
    return ['text' => $text, 'items' => $items, 'suggestions' => [], 'confidence' => $confidence];
}

function turns(array $lines): array
{
    $out = [];
    foreach ($lines as $i => [$speaker, $text]) {
        $out[] = ['speaker' => $speaker, 'text' => $text];
    }
    return $out;
}

$empty = sec('', [], 0.0);

$calls = [
    // Chest pain with a red flag, waiting for doctor review.
    [
        'patient' => 'mohammed', 'doctor' => $anita, 'status' => 'completed', 'review' => 'pending',
        'step' => 'wrapup', 'language' => 'en-IN', 'days_ago' => 0, 'source' => 'manual',
        'transcript' => turns([
            ['Agent', 'Hello, am I speaking with Mohammed Khan? I am calling from the hospital on behalf of Dr. Sharma.'],
            ['Patient', 'Yes, this is Mohammed.'],
            ['Agent', 'Thank you. May I record this call? It is shared only with your doctor.'],
            ['Patient', 'Yes, that is fine.'],
            ['Agent', 'What is troubling you the most today?'],
            ['Patient', 'I get a heavy pressure in my chest when I walk uphill. It started about a week ago.'],
            ['Agent', 'Does it spread anywhere, such as your arm or jaw?'],
            ['Patient', 'Sometimes to my left arm. It goes away if I rest for ten minutes. Last night I also felt short of breath.'],
            ['Agent', 'Are you taking any medicines, or allergic to any?'],
            ['Patient', 'I take metformin 500 twice a day and amlodipine 5 in the morning. No allergies.'],
            ['Agent', 'Thank you. I have passed this to Dr. Sharma, and someone will contact you shortly.'],
        ]),
        'state' => [
            'chief_complaint' => sec('Chest pressure on exertion for one week.', ['Chest pressure on walking uphill', 'Relieved by rest in about 10 minutes'], 0.92),
            'history_of_present_illness' => sec('Heavy chest pressure when walking uphill for about a week, sometimes radiating to the left arm and relieved by rest. Breathlessness last night.', ['Onset one week ago', 'Radiates to left arm', 'Breathlessness last night'], 0.88),
            'past_history' => sec('Diabetes and hypertension.', ['Diabetes', 'Hypertension'], 0.7),
            'family_history' => $empty,
            'current_medication' => sec('Metformin 500 mg twice daily; amlodipine 5 mg every morning.', ['Metformin 500 mg BD', 'Amlodipine 5 mg OD'], 0.95),
            'drug_allergy' => sec('No known drug allergies.', ['NKDA'], 0.9),
        ],
        'red_flags' => [
            ['type' => 'chest_pain', 'severity' => 'high', 'evidence' => 'heavy pressure in my chest when I walk uphill', 'suggested_task' => 'Prioritise for same-day assessment and ECG.'],
            ['type' => 'breathlessness', 'severity' => 'medium', 'evidence' => 'I also felt short of breath', 'suggested_task' => 'Ask about resting breathlessness at the visit.'],
        ],
        'tasks' => [
            ['text' => 'Offer same-day appointment with ECG', 'source' => 'red_flag', 'section' => 'chief_complaint', 'priority' => 'high'],
            ['text' => 'Confirm family history of heart disease', 'source' => 'low_confidence', 'section' => 'family_history', 'priority' => 'normal'],
        ],
    ],
    // Routine fever, approved after the doctor edited two fields.
    [
        'patient' => 'sneha', 'doctor' => $rohan, 'status' => 'completed', 'review' => 'approved',
        'step' => 'wrapup', 'language' => 'hi-IN', 'days_ago' => 1, 'source' => 'manual',
        'transcript' => turns([
            ['Agent', 'Namaste, am I speaking with Sneha Patel?'],
            ['Patient', 'Yes, speaking.'],
            ['Agent', 'What is bothering you today?'],
            ['Patient', 'Fever and body ache since two days, with a dry cough.'],
            ['Agent', 'Are you on any medicines or allergic to any?'],
            ['Patient', 'Only paracetamol when the fever is high. I am allergic to penicillin.'],
        ]),
        'state' => [
            'chief_complaint' => sec('Fever, body ache and dry cough for two days.', ['Fever', 'Body ache', 'Dry cough'], 0.9),
            'history_of_present_illness' => sec('Fever with body ache and dry cough for two days. Taking paracetamol when the fever is high.', ['Two-day duration'], 0.8),
            'past_history' => $empty,
            'family_history' => $empty,
            'current_medication' => sec('Paracetamol as needed.', ['Paracetamol PRN'], 0.9),
            'drug_allergy' => sec('Allergic to penicillin (reaction not specified).', ['Penicillin'], 0.9),
        ],
        'original' => [
            'chief_complaint' => sec('Fever and body ache for two days.', ['Fever', 'Body ache'], 0.9),
            'history_of_present_illness' => sec('Fever with body ache for two days.', ['Two-day duration'], 0.7),
            'past_history' => $empty,
            'family_history' => $empty,
            'current_medication' => sec('Paracetamol as needed.', ['Paracetamol PRN'], 0.9),
            'drug_allergy' => sec('Allergic to penicillin (reaction not specified).', ['Penicillin'], 0.9),
        ],
        'red_flags' => [],
        'tasks' => [],
        'with_visit' => true,
    ],
    // Headache, awaiting review, no red flags.
    [
        'patient' => 'priya', 'doctor' => $anita, 'status' => 'completed', 'review' => 'pending',
        'step' => 'wrapup', 'language' => 'en-IN', 'days_ago' => 0, 'source' => 'manual',
        'transcript' => turns([
            ['Agent', 'Hello, am I speaking with Priya Nair?'],
            ['Patient', 'Yes.'],
            ['Agent', 'What is troubling you the most today?'],
            ['Patient', 'Recurring headaches on one side for the last month, usually with nausea and light sensitivity.'],
            ['Agent', 'Does anyone in your family have similar headaches?'],
            ['Patient', 'My mother gets migraines.'],
        ]),
        'state' => [
            'chief_complaint' => sec('Recurrent one-sided headaches for one month.', ['Unilateral headache', 'Nausea', 'Photophobia'], 0.91),
            'history_of_present_illness' => sec('Recurring one-sided headaches over the last month with nausea and light sensitivity.', ['Duration one month'], 0.82),
            'past_history' => $empty,
            'family_history' => sec('Mother has migraines.', ['Mother: migraine'], 0.9),
            'current_medication' => $empty,
            'drug_allergy' => $empty,
        ],
        'red_flags' => [],
        'tasks' => [
            ['text' => 'Ask about current medication and allergies', 'source' => 'low_confidence', 'section' => 'current_medication', 'priority' => 'normal'],
        ],
    ],
    // Older approved calls so analytics has a trend to draw.
    [
        'patient' => 'rahul', 'doctor' => $anita, 'status' => 'completed', 'review' => 'approved',
        'step' => 'wrapup', 'language' => 'en-IN', 'days_ago' => 3, 'source' => 'manual',
        'transcript' => turns([
            ['Agent', 'Hello, am I speaking with Rahul Verma?'],
            ['Patient', 'Yes, this is Rahul.'],
            ['Agent', 'What is troubling you today?'],
            ['Patient', 'Sore throat and fever for three days.'],
        ]),
        'state' => [
            'chief_complaint' => sec('Sore throat and fever for three days.', ['Sore throat', 'Fever'], 0.9),
            'history_of_present_illness' => sec('Sore throat and fever for three days.', [], 0.7),
            'past_history' => sec('Hypertension on amlodipine.', ['Hypertension'], 0.8),
            'family_history' => $empty,
            'current_medication' => sec('Amlodipine 5 mg once daily.', ['Amlodipine 5 mg OD'], 0.9),
            'drug_allergy' => sec('No known drug allergies.', ['NKDA'], 0.85),
        ],
        'original' => [
            'chief_complaint' => sec('Sore throat and fever for three days.', ['Sore throat', 'Fever'], 0.9),
            'history_of_present_illness' => sec('Sore throat and fever.', [], 0.6),
            'past_history' => $empty,
            'family_history' => $empty,
            'current_medication' => sec('Amlodipine 5 mg once daily.', ['Amlodipine 5 mg OD'], 0.9),
            'drug_allergy' => sec('No known drug allergies.', ['NKDA'], 0.85),
        ],
        'red_flags' => [],
        'tasks' => [],
    ],
    [
        'patient' => 'kavita', 'doctor' => $rohan, 'status' => 'completed', 'review' => 'approved',
        'step' => 'wrapup', 'language' => 'gu-IN', 'days_ago' => 4, 'source' => 'manual',
        'transcript' => turns([
            ['Agent', 'Hello, am I speaking with Kavita Joshi?'],
            ['Patient', 'Yes.'],
            ['Agent', 'What is the main problem today?'],
            ['Patient', 'Pain in both knees, worse in the morning, for several months.'],
        ]),
        'state' => [
            'chief_complaint' => sec('Bilateral knee pain for several months.', ['Knee pain, both sides'], 0.9),
            'history_of_present_illness' => sec('Both knees hurt, worse in the morning, for several months.', ['Morning stiffness'], 0.8),
            'past_history' => $empty,
            'family_history' => $empty,
            'current_medication' => $empty,
            'drug_allergy' => $empty,
        ],
        'original' => [
            'chief_complaint' => sec('Bilateral knee pain for several months.', ['Knee pain, both sides'], 0.9),
            'history_of_present_illness' => sec('Both knees hurt, worse in the morning, for several months.', ['Morning stiffness'], 0.8),
            'past_history' => $empty,
            'family_history' => $empty,
            'current_medication' => $empty,
            'drug_allergy' => $empty,
        ],
        'red_flags' => [],
        'tasks' => [],
    ],
    // Patient did not pick up.
    [
        'patient' => 'arjun', 'doctor' => $rohan, 'status' => 'failed', 'review' => 'pending',
        'step' => 'identity', 'language' => 'en-IN', 'days_ago' => 1, 'source' => 'batch',
        'transcript' => [], 'state' => [], 'red_flags' => [], 'tasks' => [],
    ],
    // A doctor-requested call waiting for the front desk to dial.
    [
        'patient' => 'kavita', 'doctor' => $anita, 'status' => 'queued', 'review' => 'pending',
        'step' => 'identity', 'language' => 'en-IN', 'days_ago' => 0, 'source' => 'doctor_requested',
        'purpose' => 'Pre-visit check before next week\'s follow-up',
        'scenario' => 'Patient has had knee pain for months and may not have taken the prescribed medication.',
        'transcript' => [], 'state' => [], 'red_flags' => [], 'tasks' => [],
    ],
];

$insertCall = $pdo->prepare("
    INSERT INTO intake_calls
        (patient_id, doctor_id, visit_id, script_key, agent_script_id, status, review_status, current_step,
         transcript, state, ai_original_state, red_flags, tasks, source, requested_by_doctor_id, purpose,
         scenario_text, language, call_mode, started_at, ended_at, created_at, updated_at)
    VALUES
        (:patient, :doctor, :visit, 'dynamic', 1, :status, :review, :step,
         :transcript, :state, :original, :red_flags, :tasks, :source, :requested_by, :purpose,
         :scenario, :language, 'dynamic', :started, :ended, :created, :created)
");

foreach ($calls as $i => $c) {
    $visit = null;
    if (!empty($c['with_visit'])) {
        $visit = start_visit_for_patient($pdo, $patients[$c['patient']], $c['doctor']);
    }
    $created = date('Y-m-d H:i:s', strtotime("-{$c['days_ago']} days -" . (10 + $i * 17) . ' minutes'));
    $done = $c['status'] === 'completed';
    $insertCall->execute([
        ':patient'      => $patients[$c['patient']],
        ':doctor'       => $c['doctor'],
        ':visit'        => $visit,
        ':status'       => $c['status'],
        ':review'       => $c['review'],
        ':step'         => $c['step'],
        ':transcript'   => json_encode($c['transcript']),
        ':state'        => json_encode($c['state'] ?: new stdClass()),
        ':original'     => isset($c['original']) ? json_encode($c['original']) : ($done ? json_encode($c['state']) : ''),
        ':red_flags'    => json_encode($c['red_flags']),
        ':tasks'        => json_encode($c['tasks']),
        ':source'       => $c['source'],
        ':requested_by' => $c['source'] === 'doctor_requested' ? $c['doctor'] : null,
        ':purpose'      => $c['purpose'] ?? '',
        ':scenario'     => $c['scenario'] ?? '',
        ':language'     => $c['language'],
        ':started'      => $c['status'] === 'queued' ? null : $created,
        ':ended'        => $done ? date('Y-m-d H:i:s', strtotime($created . ' +3 minutes')) : null,
        ':created'      => $created,
    ]);
}

// Telephony console content (stored only; no real dialler exists).
$pdo->exec("INSERT INTO telephony_numbers (number, used_for, caller_id, lines, status) VALUES
    ('+91 79 5550 0101', 'Outbound intake', 'City Hospital', 4, 'active'),
    ('+91 79 5550 0102', 'Reminders', 'City Hospital', 2, 'active')");

$pdo->commit();

echo "Seeded 2 doctors, 6 patients, 1 saved consultation and 7 intake calls.\n";
echo "Log in as anita.sharma@example.com / demo1234 (admin).\n";
