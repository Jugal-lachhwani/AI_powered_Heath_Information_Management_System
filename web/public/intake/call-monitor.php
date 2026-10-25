<?php
require __DIR__ . '/../../includes/core/icons.php';
require __DIR__ . '/../../includes/core/auth.php';
require_login();
require __DIR__ . '/../../includes/core/db.php';

$doctor = current_doctor($pdo);

$callId = (int) ($_GET['call_id'] ?? 0);
$stmt = $pdo->prepare('
    SELECT ic.*, p.name AS patient_name, p.age AS patient_age, p.gender AS patient_gender, p.uhid,
           d.full_name AS doctor_name
    FROM intake_calls ic
    JOIN patients p ON p.id = ic.patient_id
    JOIN doctors d ON d.id = ic.doctor_id
    WHERE ic.id = ?
');
$stmt->execute([$callId]);
$call = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$call) {
    header('Location: /intake/calls.php');
    exit;
}

if ($call['status'] === 'completed') {
    header('Location: /intake/call-review.php?call_id=' . $callId);
    exit;
}

if ($call['status'] === 'queued') {
    $pdo->prepare("UPDATE intake_calls SET status = 'in_progress', started_at = datetime('now') WHERE id = ?")
        ->execute([$callId]);
    $call['status'] = 'in_progress';
}

$isDynamic = $call['call_mode'] === 'dynamic';

$scriptSteps = [];
if ($isDynamic && $call['agent_script_id']) {
    $stmt = $pdo->prepare('SELECT step_key, title, prompt_text, fills_field FROM agent_script_steps WHERE script_id = ? ORDER BY position');
    $stmt->execute([(int) $call['agent_script_id']]);
    $scriptSteps = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$ai_ws_intake_url = getenv('AI_WS_INTAKE_URL') ?: 'ws://127.0.0.1:8001/ws/intake';

$page_title = 'Live Intake Call — ' . $call['patient_name'] . ' — HIMS Voice AI';
$shell_subtitle = 'Live Intake Call · ' . htmlspecialchars($call['patient_name']);
$extra_stylesheets = ['/assets/css/intake.css'];
$body_data_attrs = [
    'data-call-id' => $callId,
    'data-ai-ws-intake-url' => $ai_ws_intake_url,
    'data-call-mode' => $call['call_mode'],
    'data-script-key' => $call['script_key'],
    'data-csrf-token' => csrf_token(),
];
require __DIR__ . '/../../includes/layout/app-shell-head.php';
?>

<div class="max-w-none mx-auto space-y-4">

  <!-- Call header -->
  <div class="card p-4 flex items-center justify-between gap-4 flex-wrap">
    <div class="flex items-center gap-3">
      <span class="w-10 h-10 rounded-full bg-[var(--surface-2)] text-[var(--primary)] flex items-center justify-center">
        <?= icon('phone', 'w-5 h-5') ?>
      </span>
      <div class="leading-tight">
        <p class="font-semibold text-sm"><?= htmlspecialchars($call['patient_name']) ?></p>
        <p class="text-[11px] text-[var(--text-muted)]">
          <?= (int) $call['patient_age'] ?> yrs · <?= htmlspecialchars($call['patient_gender']) ?> ·
          <?= htmlspecialchars($call['uhid']) ?> · for Dr. <?= htmlspecialchars($call['doctor_name']) ?>
        </p>
      </div>
    </div>
    <div id="call-status-badge" class="state-badge state-listening">
      <span class="w-1.5 h-1.5 rounded-full bg-current"></span> Connecting…
    </div>
  </div>

  <!-- Red-flag banner (hidden until one is detected) -->
  <div id="redflag-banner" class="redflag-banner hidden">
    <?= icon('alert', 'w-5 h-5 shrink-0') ?>
    <div class="redflag-text">
      <strong>Red flag detected</strong> — <span id="redflag-banner-detail"></span>
    </div>
    <button type="button" id="btn-notify-doctor" class="whisper-preset">Notify Dr. <?= htmlspecialchars(explode(' ', $call['doctor_name'])[0]) ?></button>
    <button type="button" id="btn-redflag-bargein" class="px-3 py-1.5 rounded-lg bg-[var(--accent-red)] text-white text-[12px] font-medium cursor-pointer">Barge in now</button>
  </div>

  <div class="intake-columns">

    <!-- LEFT: call state + intervene -->
    <div class="space-y-4">
      <div class="card p-5 text-center">
        <div class="call-timer">
          <span id="call-timer-value" class="call-timer-value">00:00</span>
          <span id="call-timer-label" class="call-timer-label">Connecting</span>
        </div>
        <div id="speaker-indicator" class="speaker-indicator mt-4">
          <span class="bar"></span><span class="bar"></span><span class="bar"></span><span class="bar"></span>
        </div>
        <p class="text-[11px] text-[var(--text-muted)] mt-2">Language: <span id="call-language"><?= htmlspecialchars($call['language'] ?: 'en-IN') ?></span></p>
      </div>

      <div class="intervene-panel space-y-3">
        <h2 class="font-semibold text-sm flex items-center gap-2">
          <?= icon('message', 'w-4 h-4 text-[var(--primary)]') ?> Intervene
        </h2>

        <div>
          <p class="text-[10px] uppercase tracking-wide text-[var(--text-muted)] mb-1.5">Whisper to agent · patient won't hear</p>
          <textarea id="whisper-input" class="whisper-box" placeholder="Ask if she has taken her BP medicine today…"></textarea>
          <div class="flex flex-wrap gap-1.5 mt-2">
            <button type="button" class="whisper-preset" data-whisper-preset="Confirm allergies">Confirm allergies</button>
            <button type="button" class="whisper-preset" data-whisper-preset="Slow down">Slow down</button>
            <button type="button" class="whisper-preset" data-whisper-preset="Wrap up">Wrap up</button>
          </div>
          <button type="button" id="btn-send-whisper" class="w-full mt-2 py-2 rounded-xl bg-gradient-to-b from-[var(--primary)] to-[var(--primary-dark)] text-white text-[13px] font-medium cursor-pointer hover:opacity-95">
            Send whisper
          </button>
        </div>

        <button type="button" id="btn-barge-in" class="w-full py-2.5 rounded-xl bg-[var(--text)] text-[var(--bg)] text-[13px] font-semibold cursor-pointer hover:opacity-90">
          Barge in &amp; take over
        </button>
        <p class="text-[11px] text-[var(--text-muted)]">
          Barging in stops the AI's scripted playback — the call is marked handed off to you.
        </p>

        <div class="flex gap-2">
          <button type="button" id="btn-mute" class="flex-1 py-2 rounded-xl border border-[var(--border)] bg-[var(--surface-2)] text-[12px] cursor-pointer hover:bg-[var(--border)]">Mute AI</button>
          <button type="button" id="btn-hold" class="flex-1 py-2 rounded-xl border border-[var(--border)] bg-[var(--surface-2)] text-[12px] cursor-pointer hover:bg-[var(--border)]">Hold</button>
          <button type="button" id="btn-end-call" class="flex-1 py-2 rounded-xl border border-[var(--accent-red)] text-[var(--accent-red)] text-[12px] cursor-pointer hover:bg-[var(--accent-red-bg)]">End call</button>
        </div>
      </div>
    </div>

    <!-- CENTER: script progress + live transcript -->
    <div class="space-y-4">
      <div class="card p-4">
        <h2 class="font-semibold text-sm mb-3">Agent script progress</h2>
        <div id="script-stepper" class="script-stepper">
          <?php if ($scriptSteps): ?>
            <?php foreach ($scriptSteps as $step): ?>
              <span class="stepper-step is-upcoming" data-step="<?= htmlspecialchars($step['step_key']) ?>"><?= htmlspecialchars($step['title'] ?: $step['step_key']) ?></span>
            <?php endforeach; ?>
          <?php else: ?>
            <span class="stepper-step is-upcoming" data-step="identity">Identity</span>
            <span class="stepper-step is-upcoming" data-step="consent">Consent</span>
            <span class="stepper-step is-upcoming" data-step="complaint">Complaint</span>
            <span class="stepper-step is-upcoming" data-step="history">History of illness</span>
            <span class="stepper-step is-upcoming" data-step="red_flags">Red flags</span>
            <span class="stepper-step is-upcoming" data-step="meds">Meds</span>
            <span class="stepper-step is-upcoming" data-step="wrapup">Wrap up</span>
          <?php endif; ?>
        </div>
      </div>

      <div class="card p-4">
        <h2 class="font-semibold text-sm mb-3 flex items-center gap-2">
          <span class="w-2 h-2 rounded-full bg-[var(--accent-red)] animate-pulse"></span> Live transcript
        </h2>
        <div id="live-transcript" class="transcript-box" style="height: 420px;">
          <p id="transcript-placeholder" class="text-[var(--text-muted)]">Waiting for the call to start…</p>
          <p id="transcript-live" class="transcript-line"></p>
        </div>
      </div>
    </div>

    <!-- RIGHT: filling as we talk + tasks -->
    <div class="space-y-4">
      <div class="card p-4">
        <div class="flex items-center justify-between mb-3">
          <h2 class="font-semibold text-sm">Filling as we talk</h2>
          <span id="sections-filled-count" class="text-[11px] text-[var(--text-muted)]">0 of 6 sections</span>
        </div>
        <p class="text-[11px] text-[var(--text-muted)] mb-3">Nothing is saved to the visit until a doctor approves it.</p>
        <div id="confidence-cards" class="space-y-3"></div>
      </div>

      <div class="card p-4">
        <div class="flex items-center justify-between mb-3">
          <h2 class="font-semibold text-sm">Live usage</h2>
          <span id="usage-final-badge" class="text-[11px] text-[var(--text-muted)]">running</span>
        </div>
        <div class="usage-grid">
          <span>Total API calls</span><strong id="usage-api-calls">0</strong>
          <span>LLM calls</span><strong id="usage-llm-calls">0</strong>
          <span>STT calls</span><strong id="usage-stt-calls">0</strong>
          <span>Input tokens</span><strong id="usage-input-tokens">0</strong>
          <span>Output tokens</span><strong id="usage-output-tokens">0</strong>
          <span>Total tokens</span><strong id="usage-total-tokens">0</strong>
          <span>LLM time</span><strong id="usage-llm-time">0.0s</strong>
        </div>
        <p id="usage-note" class="text-[11px] text-[var(--text-muted)] mt-2">Estimated live from provider text payloads.</p>
      </div>

      <div class="card p-4">
        <h2 class="font-semibold text-sm mb-3">Tasks the agent raised</h2>
        <div id="tasks-list" class="task-checklist">
          <p id="tasks-empty" class="text-[12px] text-[var(--text-muted)]">No tasks yet.</p>
        </div>
      </div>
    </div>

  </div>
</div>

<script>
  // Only meaningful in dynamic mode — the "init" handshake payload DynamicIntakeCallSession
  // waits for. AI_backend never touches hims.sqlite directly (see its module docstring),
  // so the doctor-authored script is sent over the WS connection instead of loaded there.
  window.__intakeMonitorInit = {
    steps: <?= json_encode(array_map(fn($s) => [
        'step_key' => $s['step_key'], 'title' => $s['title'],
        'prompt_text' => $s['prompt_text'], 'fills_field' => $s['fills_field'],
    ], $scriptSteps)) ?>,
    scenario_text: <?= json_encode($call['scenario_text'] ?? '') ?>,
    patient_name: <?= json_encode($call['patient_name']) ?>
  };
</script>

<?php
$extra_scripts = [
    '/assets/js/clinical-format.js',
    '/assets/js/ws-intake-client.js',
    '/assets/js/intake-monitor.js',
];
require __DIR__ . '/../../includes/layout/app-shell-foot.php';
?>
