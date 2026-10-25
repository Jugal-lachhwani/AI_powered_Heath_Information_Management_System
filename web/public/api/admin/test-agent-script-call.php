<?php
/**
 * /api/admin/test-agent-script-call.php — 1e's "Test calls" tab: starts a real intake
 * call against the DRAFT script specifically (bypassing "must be published"),
 * so edits can be checked before going live. Non-AJAX form POST + redirect,
 * same style as /api/intake/start-call.php.
 */
require __DIR__ . '/../../../includes/core/auth.php';
require __DIR__ . '/../../../includes/core/db.php';
require_admin($pdo);

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf($_POST['csrf_token'] ?? null)) {
    header('Location: /admin/agent-config.php');
    exit;
}

$patientId = (int) ($_POST['patient_id'] ?? 0);
$scenarioText = trim($_POST['scenario_text'] ?? '');

$stmt = $pdo->prepare('SELECT id FROM patients WHERE id = ?');
$stmt->execute([$patientId]);
if (!$stmt->fetch()) {
    header('Location: /admin/agent-config.php?tab=test');
    exit;
}

$draftId = get_or_create_draft_script($pdo);

$stmt = $pdo->prepare('
    INSERT INTO intake_calls
        (patient_id, doctor_id, call_mode, agent_script_id, scenario_text, source, status)
    VALUES (?, ?, \'dynamic\', ?, ?, \'manual\', \'queued\')
');
$stmt->execute([$patientId, (int) $_SESSION['doctor_id'], $draftId, $scenarioText]);
$callId = (int) $pdo->lastInsertId();

header('Location: /intake/call-monitor.php?call_id=' . $callId);
exit;
