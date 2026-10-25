<?php
/**
 * /api/admin/save-telephony-number.php — 1h Numbers table: action=add|delete.
 * Genuinely stored/editable; not wired to any real telephony provider.
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
if ((current_doctor($pdo)['role'] ?? 'doctor') !== 'admin') {
    respond(403, ['success' => false, 'error' => 'Admins only']);
}
if (!verify_csrf($_POST['csrf_token'] ?? null)) {
    respond(400, ['success' => false, 'error' => 'Invalid form submission']);
}

$action = $_POST['action'] ?? 'add';

if ($action === 'delete') {
    $id = (int) ($_POST['id'] ?? 0);
    $pdo->prepare('DELETE FROM telephony_numbers WHERE id = ?')->execute([$id]);
    respond(200, ['success' => true]);
}

$number = trim($_POST['number'] ?? '');
if ($number === '') {
    respond(400, ['success' => false, 'error' => 'Number is required']);
}
$usedFor = trim($_POST['used_for'] ?? '');
$lines = max(1, (int) ($_POST['lines'] ?? 1));

$pdo->prepare('INSERT INTO telephony_numbers (number, used_for, lines, status) VALUES (?, ?, ?, \'active\')')
    ->execute([$number, $usedFor, $lines]);

respond(200, ['success' => true, 'id' => (int) $pdo->lastInsertId()]);
