<?php
/**
 * /api/admin/save-agent-script.php — 1e's Script tab: add/edit/remove/reorder steps on
 * the draft agent script (get_or_create_draft_script() — never the published one).
 *
 * action=add_step | edit_step | remove_step | move_step
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
$action = $_POST['action'] ?? '';

switch ($action) {
    case 'add_step':
        $maxPosStmt = $pdo->prepare('SELECT COALESCE(MAX(position), -1) FROM agent_script_steps WHERE script_id = ?');
        $maxPosStmt->execute([$draftId]);
        $maxPos = (int) $maxPosStmt->fetchColumn();
        $stmt = $pdo->prepare('
            INSERT INTO agent_script_steps (script_id, step_key, position, title, prompt_text, fills_field)
            VALUES (?, ?, ?, ?, ?, ?)
        ');
        $stepKey = trim($_POST['step_key'] ?? '') ?: ('step_' . substr(md5((string) microtime(true)), 0, 6));
        $stmt->execute([
            $draftId, $stepKey, $maxPos + 1,
            trim($_POST['title'] ?? 'New step'), trim($_POST['prompt_text'] ?? ''),
            trim($_POST['fills_field'] ?? ''),
        ]);
        respond(200, ['success' => true, 'step_id' => (int) $pdo->lastInsertId()]);
        break;

    case 'edit_step':
        $stepId = (int) ($_POST['step_id'] ?? 0);
        $stmt = $pdo->prepare('SELECT locked FROM agent_script_steps WHERE id = ? AND script_id = ?');
        $stmt->execute([$stepId, $draftId]);
        $step = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$step) {
            respond(404, ['success' => false, 'error' => 'Step not found']);
        }
        $pdo->prepare('UPDATE agent_script_steps SET title = ?, prompt_text = ?, fills_field = ? WHERE id = ?')
            ->execute([trim($_POST['title'] ?? ''), trim($_POST['prompt_text'] ?? ''), trim($_POST['fills_field'] ?? ''), $stepId]);
        respond(200, ['success' => true]);
        break;

    case 'remove_step':
        $stepId = (int) ($_POST['step_id'] ?? 0);
        $stmt = $pdo->prepare('SELECT locked FROM agent_script_steps WHERE id = ? AND script_id = ?');
        $stmt->execute([$stepId, $draftId]);
        $step = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$step) {
            respond(404, ['success' => false, 'error' => 'Step not found']);
        }
        if ((int) $step['locked'] === 1) {
            respond(400, ['success' => false, 'error' => 'This step is locked and cannot be removed']);
        }
        $pdo->prepare('DELETE FROM agent_script_steps WHERE id = ?')->execute([$stepId]);
        respond(200, ['success' => true]);
        break;

    case 'move_step':
        $stepId = (int) ($_POST['step_id'] ?? 0);
        $direction = $_POST['direction'] ?? '';
        $stmt = $pdo->prepare('SELECT id, position FROM agent_script_steps WHERE script_id = ? ORDER BY position');
        $stmt->execute([$draftId]);
        $steps = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $index = null;
        foreach ($steps as $i => $s) {
            if ((int) $s['id'] === $stepId) {
                $index = $i;
                break;
            }
        }
        if ($index === null) {
            respond(404, ['success' => false, 'error' => 'Step not found']);
        }
        $swapWith = $direction === 'up' ? $index - 1 : $index + 1;
        if ($swapWith < 0 || $swapWith >= count($steps)) {
            respond(200, ['success' => true]); // already at the edge — no-op
        }
        $update = $pdo->prepare('UPDATE agent_script_steps SET position = ? WHERE id = ?');
        $update->execute([$swapWith, $steps[$index]['id']]);
        $update->execute([$index, $steps[$swapWith]['id']]);
        respond(200, ['success' => true]);
        break;

    default:
        respond(400, ['success' => false, 'error' => 'Unknown action']);
}
