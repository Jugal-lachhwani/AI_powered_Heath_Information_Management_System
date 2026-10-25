<?php
/**
 * /api/intake/approve-call.php — doctor approves an AI intake-call draft,
 * merging it into a real visit's consultation record.
 *
 * The intake call happens BEFORE a visit exists, so intake_calls.visit_id
 * starts NULL — a visit is auto-created here the same way /patients/select.php
 * does (via the now-shared start_visit_for_patient() in includes/core/db.php).
 * Never trusts a client-submitted patient/doctor id — the intake_calls row
 * is looked up server-side by call_id only, mirroring save-consultation.php's
 * "never trust client-supplied ids" principle.
 */
require __DIR__ . '/../../../includes/core/auth.php';
require __DIR__ . '/../../../includes/core/db.php';

header('Content-Type: application/json');

function respond(int $code, array $payload): void
{
    http_response_code($code);
    echo json_encode($payload);
    exit;
}

if (empty($_SESSION['doctor_id'])) {
    respond(401, ['success' => false, 'error' => 'Not authenticated']);
}

if (!verify_csrf($_POST['csrf_token'] ?? null)) {
    respond(400, ['success' => false, 'error' => 'Invalid form submission']);
}

$callId = (int) ($_POST['call_id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM intake_calls WHERE id = ?');
$stmt->execute([$callId]);
$call = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$call) {
    respond(404, ['success' => false, 'error' => 'Intake call not found']);
}
if ($call['status'] !== 'completed') {
    respond(400, ['success' => false, 'error' => 'Call has not completed yet']);
}

$visitId = (int) $call['visit_id'];
if ($visitId === 0) {
    $visitId = start_visit_for_patient($pdo, (int) $call['patient_id'], (int) $_SESSION['doctor_id']);
}

$intakeState = json_decode($call['state'], true) ?: [];

function emptySection(): array
{
    return ['text' => '', 'items' => [], 'suggestions' => [], 'confidence' => 0.0];
}

$section = function (string $key) use ($intakeState): array {
    $s = $intakeState[$key] ?? [];
    return [
        'text' => $s['text'] ?? '',
        'items' => $s['items'] ?? [],
        'suggestions' => $s['suggestions'] ?? [],
        'confidence' => $s['confidence'] ?? 0.0,
    ];
};

$hpi = $section('history_of_present_illness');
$currentMedText = trim($intakeState['current_medication']['text'] ?? '');
if ($currentMedText !== '') {
    $hpi['text'] = trim($hpi['text'] . "\n\nCurrent medication (from AI intake call): " . $currentMedText);
}

$consultationData = [
    'chief_complaint' => $section('chief_complaint'),
    'history_of_present_illness' => $hpi,
    'past_history' => $section('past_history'),
    'family_history' => $section('family_history'),
    'drug_allergy' => $section('drug_allergy'),
    'clinical_examination' => array_merge(emptySection(), [
        'vitals' => [], 'general_examination' => '', 'systemic_examination' => '',
    ]),
    'diagnosis' => array_merge(emptySection(), ['icd10' => []]),
    'investigations' => emptySection(),
    'prescription' => array_merge(emptySection(), ['medications' => []]),
    'advice' => emptySection(),
    'follow_up' => array_merge(emptySection(), ['date_text' => '', 'instruction' => '']),
    'follow_up_date_iso' => '',
    // Extra key, ignored by the main consultation UI's applyClinicalExtraction() —
    // kept for provenance/reference alongside the HPI-appended text above.
    'current_medication' => $section('current_medication'),
];

$stmt = $pdo->prepare('
    INSERT INTO consultations (visit_id, patient_id, doctor_id, status, data, updated_at)
    VALUES (?, ?, ?, \'draft\', ?, datetime(\'now\'))
    ON CONFLICT(visit_id) DO UPDATE SET
        data = excluded.data,
        updated_at = datetime(\'now\')
');
$stmt->execute([$visitId, (int) $call['patient_id'], (int) $_SESSION['doctor_id'], json_encode($consultationData)]);

$pdo->prepare("UPDATE intake_calls SET review_status = 'approved', visit_id = ? WHERE id = ?")
    ->execute([$visitId, $callId]);

$pdo->prepare('INSERT INTO intake_call_events (intake_call_id, doctor_id, event_type) VALUES (?, ?, \'approved\')')
    ->execute([$callId, (int) $_SESSION['doctor_id']]);

respond(200, ['success' => true, 'visit_id' => $visitId]);
