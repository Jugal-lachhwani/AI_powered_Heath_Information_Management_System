<?php
/**
 * /api/admin/save-telephony-settings.php — 1h's calling-window / retention / SMS-sender
 * forms all post here, each sending only its own fields — existing values for
 * fields not present in this particular request are preserved.
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

$current = $pdo->query('SELECT * FROM telephony_settings WHERE id = 1')->fetch(PDO::FETCH_ASSOC);

$days = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
$hasDayField = false;
foreach ($days as $d) {
    if (isset($_POST['day_' . $d])) { $hasDayField = true; break; }
}
$callingWindowJson = $current['calling_window_json'];
if ($hasDayField) {
    $window = json_decode($current['calling_window_json'], true) ?: [];
    foreach ($days as $d) {
        if (isset($_POST['day_' . $d])) {
            $window[$d] = trim($_POST['day_' . $d]);
        }
    }
    $callingWindowJson = json_encode($window);
}

$retentionDays = isset($_POST['retention_days']) ? max(1, (int) $_POST['retention_days']) : (int) $current['retention_days'];
$maskNumbers = isset($_POST['mask_numbers']) ? 1 : (isset($_POST['retention_days']) ? 0 : (int) $current['mask_numbers']);
$smsSenderId = isset($_POST['sms_sender_id']) ? trim($_POST['sms_sender_id']) : $current['sms_sender_id'];

$pdo->prepare('
    UPDATE telephony_settings SET
        calling_window_json = ?, retention_days = ?, mask_numbers = ?, sms_sender_id = ?, updated_at = datetime(\'now\')
    WHERE id = 1
')->execute([$callingWindowJson, $retentionDays, $maskNumbers, $smsSenderId]);

respond(200, ['success' => true]);
