<?php
/**
 * includes/core/pdf.php — server-side PDF generation for a visit's consultation.
 *
 * Renders /consultation/print.php through a real, installed browser (headless
 * Chrome/Chromium's --print-to-pdf) rather than a PHP-native PDF library —
 * /consultation/print.php uses Tailwind CDN + CSS custom properties, which need
 * a real JS engine to render correctly; a library like Dompdf can't execute
 * JavaScript at all and would reproduce the plain/unstyled look.
 *
 * Since the headless browser process has no session cookie, /consultation/print.php
 * accepts a short-lived HMAC-signed token (exp + sig) as an alternate to normal
 * doctor login, scoped to exactly one visit_id for ~60 seconds.
 */

// Low-sensitivity secret: only ever gates read access to a visit's own already-
// saved consultation data, for ~60 seconds. Rotate if ever exposed.
const PDF_TOKEN_SECRET = 'ebc77d9b934cf3d088e20e26afdb3cd70d076dd022d9daf1ae58c69adb816b17';

function pdf_sign_token(int $visitId, int $expiresAt): string
{
    return hash_hmac('sha256', $visitId . '|' . $expiresAt, PDF_TOKEN_SECRET);
}

function pdf_verify_token(int $visitId, int $expiresAt, string $signature): bool
{
    if ($expiresAt < time()) {
        return false;
    }
    return hash_equals(pdf_sign_token($visitId, $expiresAt), $signature);
}

/**
 * Same absolute-path-or-PATH lookup pattern as the legacy transcribe endpoint's findBinary() helper — absolute-path-or-PATH lookup.
 */
function find_chrome_binary(): ?string
{
    $candidates = [
        '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
        'google-chrome',
        'chromium',
        'chromium-browser',
    ];

    foreach ($candidates as $candidate) {
        if (strpos($candidate, '/') === 0) {
            if (is_file($candidate)) {
                return $candidate;
            }
            continue;
        }
        $resolved = trim((string) shell_exec('command -v ' . escapeshellarg($candidate) . ' 2>/dev/null'));
        if ($resolved !== '') {
            return $resolved;
        }
    }
    return null;
}

/**
 * Render a visit's consultation to PDF bytes via headless Chrome.
 * Throws RuntimeException on any failure (missing binary, render failure, empty output).
 */
function render_visit_pdf_bytes(int $visitId): string
{
    $chrome = find_chrome_binary();
    if (!$chrome) {
        throw new RuntimeException('Chrome/Chromium was not found on this server — cannot generate a PDF.');
    }

    $expiresAt = time() + 60;
    $sig = pdf_sign_token($visitId, $expiresAt);
    $host = $_SERVER['HTTP_HOST'] ?? '127.0.0.1:8000';
    $url = "http://{$host}/consultation/print.php?visit_id={$visitId}&exp={$expiresAt}&sig={$sig}";

    $tmpFile = sys_get_temp_dir() . '/hims_pdf_' . bin2hex(random_bytes(8)) . '.pdf';

    $cmd = escapeshellarg($chrome)
        . ' --headless=new --disable-gpu --no-sandbox'
        . ' --print-to-pdf=' . escapeshellarg($tmpFile)
        . ' --no-pdf-header-footer --no-margins'
        . ' ' . escapeshellarg($url)
        . ' 2>&1';

    exec($cmd, $output, $exitCode);

    if (!is_file($tmpFile) || filesize($tmpFile) === 0) {
        @unlink($tmpFile);
        throw new RuntimeException('PDF generation failed (exit code ' . $exitCode . '): ' . implode(' ', $output));
    }

    $bytes = file_get_contents($tmpFile);
    @unlink($tmpFile);

    if ($bytes === false || $bytes === '') {
        throw new RuntimeException('PDF generation produced no output.');
    }

    return $bytes;
}
