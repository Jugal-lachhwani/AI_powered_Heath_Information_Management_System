<?php
/**
 * /api/admin/publish-agent-script.php — flips the draft (get_or_create_draft_script())
 * to published, archiving whatever was published before. Validates a "consent"
 * step exists — matching the wireframe's "locked — cannot be removed" consent
 * step, this is where that guarantee is actually enforced.
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

$draftId = get_or_create_draft_script($pdo);

$stmt = $pdo->prepare("SELECT id FROM agent_script_steps WHERE script_id = ? AND step_key = 'consent' LIMIT 1");
$stmt->execute([$draftId]);
if (!$stmt->fetch()) {
    respond(400, ['success' => false, 'error' => 'Script must include a "consent" step before it can be published.']);
}

$countStmt = $pdo->prepare('SELECT COUNT(*) FROM agent_script_steps WHERE script_id = ?');
$countStmt->execute([$draftId]);
if ((int) $countStmt->fetchColumn() < 1) {
    respond(400, ['success' => false, 'error' => 'Script has no steps.']);
}

$pdo->beginTransaction();
try {
    $pdo->exec("UPDATE agent_scripts SET status = 'archived' WHERE status = 'published'");
    $pdo->prepare("UPDATE agent_scripts SET status = 'published', published_at = datetime('now') WHERE id = ?")
        ->execute([$draftId]);
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    respond(500, ['success' => false, 'error' => 'Could not publish script.']);
}

$versionStmt = $pdo->prepare('SELECT version FROM agent_scripts WHERE id = ?');
$versionStmt->execute([$draftId]);
respond(200, ['success' => true, 'script_id' => $draftId, 'version' => (int) $versionStmt->fetchColumn()]);
