<?php
/**
 * /api/admin/save-doctor-role.php — /admin/telephony.php's "Doctors & access" card:
 * promote a doctor to admin or demote an admin back to doctor.
 *
 * Refuses to demote the last remaining admin — otherwise the clinic could lock
 * itself out of /admin/telephony.php/agent-config.php with no way back in short
 * of editing the database directly.
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

$targetId = (int) ($_POST['doctor_id'] ?? 0);
$newRole = $_POST['role'] ?? '';
if (!in_array($newRole, ['doctor', 'admin'], true)) {
    respond(400, ['success' => false, 'error' => 'Invalid role']);
}

$stmt = $pdo->prepare('SELECT role FROM doctors WHERE id = ?');
$stmt->execute([$targetId]);
$target = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$target) {
    respond(404, ['success' => false, 'error' => 'Doctor not found']);
}

if ($target['role'] === 'admin' && $newRole !== 'admin') {
    $adminCount = (int) $pdo->query("SELECT COUNT(*) FROM doctors WHERE role = 'admin'")->fetchColumn();
    if ($adminCount <= 1) {
        respond(400, ['success' => false, 'error' => 'At least one admin must remain']);
    }
}

$pdo->prepare('UPDATE doctors SET role = ? WHERE id = ?')->execute([$newRole, $targetId]);

respond(200, ['success' => true]);
