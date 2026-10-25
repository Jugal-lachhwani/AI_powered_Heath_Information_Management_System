<?php
/**
 * /api/intake/start-call.php — starts a mocked AI intake call for a patient.
 *
 * Non-AJAX form POST + redirect (like /patients/select.php's start_visit action),
 * not a JSON API — this is a navigation action, not an autosave.
 *
 * call_mode=dynamic (default): uses the currently published agent_scripts row —
 * the agent's questions AND the patient's answers are generated live by the LLM,
 * seeded by scenario_text. call_mode=fixed: plays one of the two canned
 * mock_intake_scripts.py scripts end to end (script_key), no scenario needed —
 * kept as a reliable, LLM-quota-light fallback/demo path.
 */
require __DIR__ . '/../../../includes/core/auth.php';
require_login();
require __DIR__ . '/../../../includes/core/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf($_POST['csrf_token'] ?? null)) {
    header('Location: /intake/calls.php');
    exit;
}

$patientId = (int) ($_POST['patient_id'] ?? 0);
$callMode = ($_POST['call_mode'] ?? 'dynamic') === 'fixed' ? 'fixed' : 'dynamic';
$scriptKey = $_POST['script_key'] ?? 'chest_pain_flag';
if (!in_array($scriptKey, ['chest_pain_flag', 'routine_follow_up'], true)) {
    $scriptKey = 'chest_pain_flag';
}
$scenarioText = trim($_POST['scenario_text'] ?? '');
$purpose = trim($_POST['purpose'] ?? '');
$language = $_POST['language'] ?? 'en-IN';
$sourceInput = $_POST['source'] ?? 'manual';
$source = in_array($sourceInput, ['manual', 'doctor_requested', 'batch'], true) ? $sourceInput : 'manual';

$stmt = $pdo->prepare('SELECT id FROM patients WHERE id = ?');
$stmt->execute([$patientId]);
if (!$stmt->fetch()) {
    header('Location: /intake/calls.php');
    exit;
}

$agentScriptId = null;
if ($callMode === 'dynamic') {
    $agentScriptId = (int) $pdo->query("SELECT id FROM agent_scripts WHERE status = 'published' ORDER BY id DESC LIMIT 1")->fetchColumn();
    if (!$agentScriptId) {
        // No published script exists — fall back to fixed mode rather than start a
        // dynamic call with no steps at all.
        $callMode = 'fixed';
        $agentScriptId = null;
    }
}

$stmt = $pdo->prepare('
    INSERT INTO intake_calls
        (patient_id, doctor_id, script_key, call_mode, agent_script_id, scenario_text, purpose, language, source, status)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, \'queued\')
');
$stmt->execute([
    $patientId, (int) $_SESSION['doctor_id'], $scriptKey, $callMode, $agentScriptId,
    $scenarioText, $purpose, $language, $source,
]);
$callId = (int) $pdo->lastInsertId();

header('Location: /intake/call-monitor.php?call_id=' . $callId);
exit;
