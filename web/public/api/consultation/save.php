<?php
/**
 * /api/consultation/save.php — AJAX endpoint for Save Draft / Save Consultation.
 *
 * Always writes to the CURRENT session's visit (never a client-supplied visit_id),
 * so a tampered request can only ever affect the doctor's own active visit.
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

if (empty($_SESSION['doctor_id']) || empty($_SESSION['visit_id']) || empty($_SESSION['patient_id'])) {
    respond(401, ['success' => false, 'error' => 'Not authenticated']);
}

if (!verify_csrf($_POST['csrf_token'] ?? null)) {
    respond(400, ['success' => false, 'error' => 'Invalid form submission']);
}

$status = $_POST['status'] ?? '';
if (!in_array($status, ['draft', 'final'], true)) {
    respond(400, ['success' => false, 'error' => 'Invalid status']);
}

$data = $_POST['data'] ?? '';
if ($data === '' || json_decode($data) === null) {
    respond(400, ['success' => false, 'error' => 'Invalid consultation data']);
}

$stmt = $pdo->prepare('
    INSERT INTO consultations (visit_id, patient_id, doctor_id, status, data, updated_at)
    VALUES (?, ?, ?, ?, ?, datetime(\'now\'))
    ON CONFLICT(visit_id) DO UPDATE SET
        status = excluded.status,
        data = excluded.data,
        updated_at = datetime(\'now\')
');
$stmt->execute([
    $_SESSION['visit_id'],
    $_SESSION['patient_id'],
    $_SESSION['doctor_id'],
    $status,
    $data,
]);

respond(200, ['success' => true, 'status' => $status]);
