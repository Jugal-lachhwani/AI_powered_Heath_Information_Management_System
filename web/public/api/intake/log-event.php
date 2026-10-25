<?php
/**
 * /api/intake/log-event.php — audit trail for an intake call (whisper,
 * barge_in, mute, hold, red_flag_detected, notify_doctor, call_ended, ...).
 *
 * Called by the browser JS proactively when IT sends a control frame (not on
 * an echo from the server) — see /assets/js/intake-monitor.js.
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

$eventType = $_POST['event_type'] ?? '';
$allowedEvents = [
    'whisper', 'barge_in', 'mute', 'unmute', 'hold', 'resume',
    'red_flag_detected', 'notify_doctor', 'call_ended', 'approved', 'discarded',
];
if (!in_array($eventType, $allowedEvents, true)) {
    respond(400, ['success' => false, 'error' => 'Invalid event_type']);
}

$payload = $_POST['payload'] ?? '{}';
if (json_decode($payload) === null) {
    $payload = '{}';
}

$stmt = $pdo->prepare('
    INSERT INTO intake_call_events (intake_call_id, doctor_id, event_type, payload)
    VALUES (?, ?, ?, ?)
');
$stmt->execute([$callId, (int) $_SESSION['doctor_id'], $eventType, $payload]);

respond(200, ['success' => true]);
