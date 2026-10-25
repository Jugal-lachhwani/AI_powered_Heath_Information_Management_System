<?php
/**
 * web/public/index.php — site root. Sends signed-in clinicians to the intake dashboard
 * (require_login() redirects everyone else to the login page).
 */
require __DIR__ . '/../includes/core/auth.php';
require_login();
header('Location: /intake/dashboard.php');
exit;
