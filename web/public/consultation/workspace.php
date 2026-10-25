<?php
/**
 * AI Voice Consultation Module — Main Page
 * HIMS Practice Module
 */
require __DIR__ . '/../../includes/core/icons.php';
require __DIR__ . '/../../includes/core/auth.php';
require_login();
require __DIR__ . '/../../includes/core/db.php';

if (empty($_SESSION['visit_id'])) {
    header('Location: /patients/select.php');
    exit;
}

$stmt = $pdo->prepare('
    SELECT p.name, p.age, p.gender, p.uhid,
           v.visit_no, v.visit_datetime,
           d.full_name AS doctor_name
    FROM visits v
    JOIN patients p ON p.id = v.patient_id
    JOIN doctors  d ON d.id = v.doctor_id
    WHERE v.id = ?
');
$stmt->execute([$_SESSION['visit_id']]);
$visit_row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$visit_row) {
    unset($_SESSION['visit_id']);
    header('Location: /patients/select.php');
    exit;
}

$patient = [
    'name'     => $visit_row['name'],
    'age'      => (int) $visit_row['age'],
    'gender'   => $visit_row['gender'],
    'uhid'     => $visit_row['uhid'],
    'visit_no' => $visit_row['visit_no'],
    'doctor'   => 'Dr. ' . $visit_row['doctor_name'],
    // Always the current time on page load (IST, set globally in includes/core/db.php),
    // not the visit's stored creation timestamp.
    'datetime' => date('d M Y, h:i A') . ' IST',
];

require __DIR__ . '/../../data/mock-data.php';

// Restore this visit's saved draft/consultation, if one exists.
$stmt = $pdo->prepare('SELECT data FROM consultations WHERE visit_id = ?');
$stmt->execute([$_SESSION['visit_id']]);
$saved_row = $stmt->fetch(PDO::FETCH_ASSOC);
$saved_consultation_data = $saved_row ? $saved_row['data'] : null;

// This patient's other visits that already have a saved consultation — shown as history.
$stmt = $pdo->prepare('
    SELECT v.id, v.visit_no, v.visit_datetime, d.full_name AS doctor_name, c.status
    FROM visits v
    JOIN consultations c ON c.visit_id = v.id
    JOIN doctors d ON d.id = v.doctor_id
    WHERE v.patient_id = ? AND v.id != ?
    ORDER BY v.visit_datetime DESC
    LIMIT 10
');
$stmt->execute([$_SESSION['patient_id'], $_SESSION['visit_id']]);
$previous_visits = $stmt->fetchAll(PDO::FETCH_ASSOC);

$ai_ws_url = getenv('AI_WS_URL') ?: 'ws://127.0.0.1:8001/ws/stream';
$ai_ws_sample_rate = getenv('AI_WS_SAMPLE_RATE') ?: '24000';
$consultation_mode = ($_SESSION['consultation_mode'] ?? 'direct') === 'google_meet' ? 'google_meet' : 'direct';

require __DIR__ . '/../../includes/consultation/header.php';
require __DIR__ . '/../../includes/consultation/left-panel.php';
require __DIR__ . '/../../includes/consultation/center-panel.php';
require __DIR__ . '/../../includes/consultation/right-panel.php';
require __DIR__ . '/../../includes/consultation/footer.php';
