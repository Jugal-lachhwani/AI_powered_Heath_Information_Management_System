<?php
/**
 * /api/intake/schedule-batch.php — 1d "Schedule calls": creates queued intake_calls
 * rows for a batch of patients. Does NOT autonomously dial anyone — there is no
 * background worker in this PHP+SQLite app (see the plan's documented decision).
 * Front desk works the queue one call at a time from /intake/dashboard.php, the
 * same way doctor-requested calls already work.
 */
require __DIR__ . '/../../../includes/core/auth.php';
require_login();
require __DIR__ . '/../../../includes/core/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf($_POST['csrf_token'] ?? null)) {
    header('Location: /intake/queue.php');
    exit;
}

$who = $_POST['who'] ?? 'all';
$skipNoConsent = ($_POST['skip_no_consent'] ?? '1') === '1';
$language = $_POST['language'] ?? 'en-IN';
$startAt = trim($_POST['start_at'] ?? '');
$stopBy = trim($_POST['stop_by'] ?? '');
$retryEnabled = !empty($_POST['retry_enabled']);
$retryMax = $retryEnabled ? max(0, min(5, (int) ($_POST['retry_max'] ?? 0))) : 0;

$whereConsent = $skipNoConsent ? 'AND ai_call_consent = 1' : '';

if ($who === 'csv') {
    $uhids = array_filter(array_map('trim', explode("\n", $_POST['csv_uhids'] ?? '')));
    $patients = [];
    if ($uhids) {
        $placeholders = implode(',', array_fill(0, count($uhids), '?'));
        $stmt = $pdo->prepare("SELECT id, name, uhid, ai_call_consent FROM patients WHERE uhid IN ($placeholders) $whereConsent");
        $stmt->execute($uhids);
        $patients = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} else {
    $patients = $pdo->query("SELECT id, name, uhid, ai_call_consent FROM patients WHERE 1=1 $whereConsent")->fetchAll(PDO::FETCH_ASSOC);
}

$agentScriptId = (int) $pdo->query("SELECT id FROM agent_scripts WHERE status = 'published' ORDER BY id DESC LIMIT 1")->fetchColumn();
$callMode = $agentScriptId ? 'dynamic' : 'fixed';
$preferredTime = $stopBy !== '' ? 'Stop by ' . $stopBy : '';

$stmt = $pdo->prepare('
    INSERT INTO intake_calls
        (patient_id, doctor_id, source, purpose, language, scheduled_at, preferred_time,
         retry_max, call_mode, agent_script_id, status)
    VALUES (?, ?, \'batch\', \'Pre-visit symptom intake\', ?, ?, ?, ?, ?, ?, \'queued\')
');
$scheduled = 0;
foreach ($patients as $p) {
    $stmt->execute([
        $p['id'], (int) $_SESSION['doctor_id'], $language, $startAt ?: null, $preferredTime,
        $retryMax, $callMode, $agentScriptId ?: null,
    ]);
    $scheduled++;
}

header('Location: /intake/dashboard.php?scheduled=' . $scheduled);
exit;
