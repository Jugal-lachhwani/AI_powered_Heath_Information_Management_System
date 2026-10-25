<?php
/**
 * /api/consultation/send-email.php — AJAX endpoint for Send Email.
 *
 * Generates a PDF of the CURRENT session's visit (never a client-supplied
 * visit_id) via includes/core/pdf.php, and emails it to a doctor-entered address
 * using PHPMailer over Gmail SMTP (web/config/smtp-config.php, git-ignored).
 */
require __DIR__ . '/../../../includes/core/auth.php';
require __DIR__ . '/../../../includes/core/db.php';
require __DIR__ . '/../../../includes/core/pdf.php';
require __DIR__ . '/../../../includes/vendor/PHPMailer/Exception.php';
require __DIR__ . '/../../../includes/vendor/PHPMailer/PHPMailer.php';
require __DIR__ . '/../../../includes/vendor/PHPMailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

header('Content-Type: application/json');
set_time_limit(60); // headless-Chrome render + SMTP send both take real time

function respond(int $code, array $payload): void
{
    http_response_code($code);
    echo json_encode($payload);
    exit;
}

if (empty($_SESSION['doctor_id']) || empty($_SESSION['visit_id'])) {
    respond(401, ['success' => false, 'error' => 'Not authenticated']);
}

if (!verify_csrf($_POST['csrf_token'] ?? null)) {
    respond(400, ['success' => false, 'error' => 'Invalid form submission']);
}

$toEmail = trim($_POST['email'] ?? '');
if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
    respond(400, ['success' => false, 'error' => 'Please enter a valid email address']);
}

$configPath = __DIR__ . '/../../../config/smtp-config.php';
if (!is_file($configPath)) {
    respond(500, ['success' => false, 'error' => 'Email is not configured on this server yet']);
}
$smtp = require $configPath;

$visitId = (int) $_SESSION['visit_id'];

$stmt = $pdo->prepare('
    SELECT p.name AS patient_name, v.visit_no, v.visit_datetime, d.full_name AS doctor_name
    FROM visits v
    JOIN patients p ON p.id = v.patient_id
    JOIN doctors  d ON d.id = v.doctor_id
    WHERE v.id = ?
');
$stmt->execute([$visitId]);
$visit = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$visit) {
    respond(404, ['success' => false, 'error' => 'Visit not found']);
}

try {
    $pdfBytes = render_visit_pdf_bytes($visitId);
} catch (RuntimeException $e) {
    respond(500, ['success' => false, 'error' => 'Could not generate the PDF: ' . $e->getMessage()]);
}

$mail = new PHPMailer(true);
try {
    $mail->isSMTP();
    $mail->Host = $smtp['host'];
    $mail->Port = $smtp['port'];
    $mail->SMTPAuth = true;
    $mail->Username = $smtp['username'];
    $mail->Password = $smtp['password'];
    $mail->SMTPSecure = $smtp['encryption'];

    $mail->setFrom($smtp['from_email'], $smtp['from_name']);
    $mail->addAddress($toEmail);
    $mail->addStringAttachment($pdfBytes, 'consultation.pdf', 'base64', 'application/pdf');

    $mail->isHTML(true);
    $mail->Subject = 'Your Consultation Summary — HIMS Voice AI';
    $mail->Body = sprintf(
        '<p>Dear %s,</p><p>Please find attached the summary of your consultation on %s with Dr. %s.</p><p>— HIMS Voice AI</p>',
        htmlspecialchars($visit['patient_name']),
        htmlspecialchars(date('d M Y', strtotime($visit['visit_datetime']))),
        htmlspecialchars($visit['doctor_name'])
    );

    $mail->send();
} catch (PHPMailerException $e) {
    respond(500, ['success' => false, 'error' => 'Could not send the email: ' . $mail->ErrorInfo]);
}

respond(200, ['success' => true]);
