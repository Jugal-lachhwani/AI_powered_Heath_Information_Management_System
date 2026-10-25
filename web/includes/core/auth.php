<?php
/**
 * includes/core/auth.php — session + auth helpers.
 *
 * Require this once per request, before includes/core/db.php, before any output.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function require_login(): void
{
    if (empty($_SESSION['doctor_id'])) {
        header('Location: /auth/login.php');
        exit;
    }
}

function current_doctor(PDO $pdo): ?array
{
    if (empty($_SESSION['doctor_id'])) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT id, full_name, email, role FROM doctors WHERE id = ?');
    $stmt->execute([$_SESSION['doctor_id']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Gate a page to admins only (/admin/telephony.php, /admin/agent-config.php — clinic-wide
 * config that affects every doctor's calls). Always re-checks the role against the
 * DB rather than trusting anything cached in session, so a demotion takes effect
 * on the demoted doctor's very next request.
 */
function require_admin(PDO $pdo): void
{
    require_login();
    $doctor = current_doctor($pdo);
    if (($doctor['role'] ?? 'doctor') !== 'admin') {
        http_response_code(403);
        echo '<!doctype html><meta charset="utf-8"><body style="font-family:system-ui,sans-serif;padding:60px;text-align:center;color:#333">'
            . '<h2>403 — Admins only</h2><p>Ask a clinic admin for access, or return to the <a href="/intake/dashboard.php">dashboard</a>.</p></body>';
        exit;
    }
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(?string $submitted): bool
{
    return is_string($submitted)
        && isset($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $submitted);
}
