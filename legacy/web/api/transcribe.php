<?php
/**
 * Transcribes an uploaded audio file to text using a local whisper.cpp install.
 * 100% free / offline — no third-party API key or internet connection required.
 * Works on macOS, Linux and Windows — see README.md for one-time setup on each OS.
 */

header('Content-Type: application/json');

const IS_WINDOWS = PHP_OS_FAMILY === 'Windows';
const NULL_DEVICE = IS_WINDOWS ? 'NUL' : '/dev/null';

function fail(string $message, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

function findBinary(array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        $looksAbsolute = strpos($candidate, '/') === 0 || preg_match('/^[A-Za-z]:[\\\\\/]/', $candidate);
        if ($looksAbsolute) {
            if (is_file($candidate)) return $candidate;
            continue;
        }
        $lookupCmd = IS_WINDOWS ? 'where' : 'command -v';
        $resolved = trim((string) shell_exec($lookupCmd . ' ' . escapeshellarg($candidate) . ' 2>' . NULL_DEVICE));
        // "where" can print multiple matches, one per line — use the first.
        $resolved = trim(strtok($resolved, "\r\n"));
        if ($resolved !== '') return $resolved;
    }
    return null;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail('Method not allowed', 405);
}

if (isset($_FILES['audio']) && in_array($_FILES['audio']['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
    fail('This file is larger than the server currently allows. Increase upload_max_filesize / post_max_size in php.ini and restart the server.');
}

if (!isset($_FILES['audio']) || $_FILES['audio']['error'] !== UPLOAD_ERR_OK) {
    fail('No audio file was received');
}

$maxBytes = 60 * 1024 * 1024;
if ($_FILES['audio']['size'] > $maxBytes) {
    fail('File is too large (max 50MB)');
}

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = (string) finfo_file($finfo, $_FILES['audio']['tmp_name']);
if (strpos($mime, 'audio/') !== 0 && strpos($mime, 'video/') !== 0) {
    // Browser MediaRecorder blobs sometimes report as video/webm despite being audio-only.
    fail('The uploaded file does not look like an audio recording');
}

$allowedLangs = ['en', 'hi', 'gu'];
$langParam = strtolower(substr((string) ($_POST['lang'] ?? 'en'), 0, 2));
$lang = in_array($langParam, $allowedLangs, true) ? $langParam : 'en';

$whisperBin = findBinary(['/opt/homebrew/bin/whisper-cli', '/usr/local/bin/whisper-cli', 'whisper-cli', 'whisper-cli.exe', 'main.exe']);
if (!$whisperBin) {
    fail('whisper-cli was not found. See README.md to install whisper.cpp for your OS.', 500);
}

$ffmpegBin = findBinary(['/opt/homebrew/bin/ffmpeg', '/usr/local/bin/ffmpeg', 'ffmpeg', 'ffmpeg.exe']);
if (!$ffmpegBin) {
    fail('ffmpeg was not found. See README.md to install ffmpeg for your OS.', 500);
}

$modelPath = __DIR__ . '/../models/ggml-small.bin';
if (!is_file($modelPath)) {
    fail('Whisper model file is missing at models/ggml-small.bin', 500);
}

$tmpDir = __DIR__ . '/../tmp';
if (!is_dir($tmpDir)) {
    mkdir($tmpDir, 0700, true);
}

$jobId = bin2hex(random_bytes(8));
$rawPath = $tmpDir . '/upload-' . $jobId . '.audio';
$wavPath = $tmpDir . '/upload-' . $jobId . '.wav';
$outPrefix = $tmpDir . '/upload-' . $jobId;
$txtPath = $outPrefix . '.txt';

if (!move_uploaded_file($_FILES['audio']['tmp_name'], $rawPath)) {
    fail('Could not save the uploaded file', 500);
}

set_time_limit(120);

// Normalize to 16kHz mono PCM16 WAV so whisper.cpp can read any input container/codec.
$ffmpegCmd = escapeshellarg($ffmpegBin) . ' -y -i ' . escapeshellarg($rawPath) .
    ' -ar 16000 -ac 1 -c:a pcm_s16le ' . escapeshellarg($wavPath) . ' >' . NULL_DEVICE . ' 2>&1';
exec($ffmpegCmd, $unusedOutput, $ffmpegStatus);

if ($ffmpegStatus !== 0 || !is_file($wavPath)) {
    @unlink($rawPath);
    fail('Could not process this audio file — is it a valid recording?', 500);
}

$whisperCmd = escapeshellarg($whisperBin) .
    ' -m ' . escapeshellarg($modelPath) .
    ' -f ' . escapeshellarg($wavPath) .
    ' -l ' . escapeshellarg($lang) .
    ' -otxt -of ' . escapeshellarg($outPrefix) .
    ' -np >' . NULL_DEVICE . ' 2>&1';
exec($whisperCmd, $unusedOutput2, $whisperStatus);

$text = is_file($txtPath) ? trim((string) file_get_contents($txtPath)) : '';

@unlink($rawPath);
@unlink($wavPath);
@unlink($txtPath);

if ($whisperStatus !== 0) {
    fail('Transcription failed', 500);
}

if ($text === '') {
    echo json_encode(['success' => true, 'text' => '', 'note' => 'No speech was detected in this recording.']);
    exit;
}

echo json_encode(['success' => true, 'text' => $text]);
