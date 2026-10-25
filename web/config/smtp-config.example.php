<?php
/**
 * web/config/smtp-config.example.php — template for web/config/smtp-config.php (git-ignored).
 *
 * Copy this file to web/config/smtp-config.php and fill in real values.
 * 'password' must be a Google "App Password" (myaccount.google.com/apppasswords),
 * NOT your regular Gmail account password — requires 2FA to be enabled on the account.
 */

return [
    'host'       => 'smtp.gmail.com',
    'port'       => 587,
    'encryption' => 'tls',
    'username'   => 'your.email@gmail.com',
    'password'   => 'your-16-char-app-password',
    'from_email' => 'your.email@gmail.com',
    'from_name'  => 'HIMS Voice AI',
];
