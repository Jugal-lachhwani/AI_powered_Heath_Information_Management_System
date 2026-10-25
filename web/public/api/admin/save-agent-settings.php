<?php
/**
 * /api/admin/save-agent-settings.php — 1e's Voice & Language tab: persona/voice pacing
 * settings (singleton agent_settings row, id=1). NOT the guardrails list — those
 * are fixed/read-only, baked into AI_backend's agent_turn.py prompt as constants.
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

$personaVoice = trim($_POST['persona_voice'] ?? '') ?: 'Asha - warm, female';
$speakingSpeed = max(0.5, min(1.5, (float) ($_POST['speaking_speed'] ?? 0.9)));
$pause = max(0.0, min(2.0, (float) ($_POST['pause_before_replying'] ?? 0.6)));
$allowInterrupt = !empty($_POST['allow_interrupt']) ? 1 : 0;

$pdo->prepare('
    UPDATE agent_settings SET
        persona_voice = ?, speaking_speed = ?, pause_before_replying = ?,
        allow_interrupt = ?, updated_at = datetime(\'now\')
    WHERE id = 1
')->execute([$personaVoice, $speakingSpeed, $pause, $allowInterrupt]);

respond(200, ['success' => true]);
