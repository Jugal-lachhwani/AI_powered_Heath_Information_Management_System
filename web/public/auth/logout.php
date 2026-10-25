<?php
require __DIR__ . '/../../includes/core/auth.php';

$_SESSION = [];
session_destroy();

if (ini_get('session.use_cookies')) {
    setcookie(session_name(), '', time() - 42000, '/');
}

header('Location: /auth/login.php');
exit;
