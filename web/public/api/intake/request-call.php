<?php
/**
 * /api/intake/request-call.php — 1i's "Ask the agent to call" form: queues a
 * doctor-requested intake call (source='doctor_requested'). Unlike
 * start-intake-call.php, this does NOT redirect to the monitor page — the
 * requesting doctor isn't necessarily the one who'll run it; it surfaces in
 * 1a's "Doctor requests waiting" for front desk to dial. There's no autonomous
 * background dialer in this app (see the plan's 1d decision) — someone has to
 * open the monitor page for the call to actually happen.
 */
require __DIR__ . '/../../../includes/core/auth.php';
require_login();
require __DIR__ . '/../../../includes/core/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf($_POST['csrf_token'] ?? null)) {
    header('Location: /intake/request-call.php');
    exit;
}

$patientId = (int) ($_POST['patient_id'] ?? 0);
$stmt = $pdo->prepare('SELECT id FROM patients WHERE id = ?');
$stmt->execute([$patientId]);
if (!$stmt->fetch()) {
    header('Location: /intake/request-call.php');
    exit;
}

$purpose = trim($_POST['purpose'] ?? 'Symptom intake');
$scenarioText = trim($_POST['scenario_text'] ?? '');
$preferredTime = trim($_POST['preferred_time'] ?? '');
$language = $_POST['language'] ?? 'en-IN';

$agentScriptId = (int) $pdo->query("SELECT id FROM agent_scripts WHERE status = 'published' ORDER BY id DESC LIMIT 1")->fetchColumn();
$callMode = $agentScriptId ? 'dynamic' : 'fixed';

$stmt = $pdo->prepare('
    INSERT INTO intake_calls
        (patient_id, doctor_id, requested_by_doctor_id, source, purpose, scenario_text,
         preferred_time, language, call_mode, agent_script_id, status)
    VALUES (?, ?, ?, \'doctor_requested\', ?, ?, ?, ?, ?, ?, \'queued\')
');
$stmt->execute([
    $patientId, (int) $_SESSION['doctor_id'], (int) $_SESSION['doctor_id'],
    $purpose, $scenarioText, $preferredTime, $language, $callMode, $agentScriptId ?: null,
]);

header('Location: /intake/request-call.php?submitted=1');
exit;
