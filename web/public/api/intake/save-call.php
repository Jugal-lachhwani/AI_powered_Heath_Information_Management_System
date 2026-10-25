<?php
/**
 * /api/intake/save-call.php — persists the running state of a mocked AI intake
 * call (called by the monitor page's JS on every interim/final WS message, and
 * by the review page's Save Draft button).
 *
 * The Python backend stays stateless/DB-free by design (see AI_backend/app/
 * pipeline/intake_call_session.py) — PHP owns all persistence, mirroring
 * save-consultation.php's shape.
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
$stmt = $pdo->prepare('SELECT status, ai_original_state FROM intake_calls WHERE id = ?');
$stmt->execute([$callId]);
$existing = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$existing) {
    respond(404, ['success' => false, 'error' => 'Intake call not found']);
}

$status = $_POST['status'] ?? '';
if (!in_array($status, ['queued', 'in_progress', 'completed', 'failed'], true)) {
    respond(400, ['success' => false, 'error' => 'Invalid status']);
}

// Snapshot the AI's own output the FIRST time a call completes — never overwritten by
// later doctor edits on the review screen (Save Draft always re-POSTs status=completed
// too) — this is what 1f's doctor-edit-rate diffs the current `state` against.
$isFirstCompletion = $status === 'completed' && $existing['status'] !== 'completed' && $existing['ai_original_state'] === '';
$aiOriginalStateClause = $isFirstCompletion ? ', ai_original_state = ?' : '';

foreach (['transcript', 'state', 'red_flags', 'tasks'] as $jsonField) {
    $value = $_POST[$jsonField] ?? '';
    if ($value !== '' && json_decode($value) === null) {
        respond(400, ['success' => false, 'error' => "Invalid $jsonField"]);
    }
}

$currentStep = $_POST['current_step'] ?? 'identity';
$humanTakenOver = !empty($_POST['human_taken_over']) ? 1 : 0;
$endedAtClause = $status === 'completed' ? ", ended_at = COALESCE(ended_at, datetime('now'))" : '';

$stmt = $pdo->prepare("
    UPDATE intake_calls SET
        status = ?,
        current_step = ?,
        human_taken_over = ?,
        transcript = ?,
        state = ?,
        red_flags = ?,
        tasks = ?,
        updated_at = datetime('now')
        $endedAtClause
        $aiOriginalStateClause
    WHERE id = ?
");
$params = [
    $status,
    $currentStep,
    $humanTakenOver,
    $_POST['transcript'] ?? '[]',
    $_POST['state'] ?? '{}',
    $_POST['red_flags'] ?? '[]',
    $_POST['tasks'] ?? '[]',
];
if ($isFirstCompletion) {
    $params[] = $_POST['state'] ?? '{}';
}
$params[] = $callId;
$stmt->execute($params);

respond(200, ['success' => true]);
