<?php
/**
 * /api/intake/discard-call.php — doctor discards an AI intake-call draft.
 * Never touches consultations/visits.
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
$stmt = $pdo->prepare('SELECT id FROM intake_calls WHERE id = ?');
$stmt->execute([$callId]);
if (!$stmt->fetch()) {
    respond(404, ['success' => false, 'error' => 'Intake call not found']);
}

$pdo->prepare("UPDATE intake_calls SET review_status = 'discarded' WHERE id = ?")->execute([$callId]);
$pdo->prepare('INSERT INTO intake_call_events (intake_call_id, doctor_id, event_type) VALUES (?, ?, \'discarded\')')
    ->execute([$callId, (int) $_SESSION['doctor_id']]);

respond(200, ['success' => true]);
