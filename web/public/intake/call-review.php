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
if (in_array($call['status'], ['queued', 'in_progress'], true)) {
    header('Location: /intake/call-monitor.php?call_id=' . $callId);
    exit;
}

$state = json_decode($call['state'], true) ?: [];
$usage = is_array($state['_usage'] ?? null) ? $state['_usage'] : [];
$redFlags = json_decode($call['red_flags'], true) ?: [];
$tasks = json_decode($call['tasks'], true) ?: [];
$transcript = json_decode($call['transcript'], true) ?: [];

$stmt = $pdo->prepare('
    SELECT event_type, payload, doctor_id, created_at
    FROM intake_call_events WHERE intake_call_id = ? ORDER BY created_at ASC
');
$stmt->execute([$callId]);
$events = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare('
    SELECT v.id, v.visit_no, v.visit_datetime, d.full_name AS doctor_name, c.status
    FROM visits v
    JOIN consultations c ON c.visit_id = v.id
    JOIN doctors d ON d.id = v.doctor_id
    WHERE v.patient_id = ?
    ORDER BY v.visit_datetime DESC
    LIMIT 10
');
$stmt->execute([$call['patient_id']]);
$previousVisits = $stmt->fetchAll(PDO::FETCH_ASSOC);

$SECTION_TITLES = [
    'chief_complaint' => ['num' => '01', 'label' => 'Chief Complaint'],
    'history_of_present_illness' => ['num' => '02', 'label' => 'History of Present Illness'],
    'current_medication' => ['num' => '03', 'label' => 'Current Medication'],
    'past_history' => ['num' => '04', 'label' => 'Past History'],
    'family_history' => ['num' => '04', 'label' => 'Family History'],
    'drug_allergy' => ['num' => '05', 'label' => 'Allergies'],
];

function confidenceLevelPhp(float $v): string
{
    if ($v >= 0.8) return 'high';
    if ($v >= 0.4) return 'mid';
    return 'low';
}

$severityRank = ['low' => 1, 'medium' => 2, 'high' => 3, 'critical' => 4];
$topSeverity = 'routine';
$topRank = 0;
foreach ($redFlags as $flag) {
    $rank = $severityRank[$flag['severity'] ?? ''] ?? 0;
    if ($rank > $topRank) { $topRank = $rank; $topSeverity = $flag['severity']; }
}

$page_title = 'Review Intake Draft — ' . $call['patient_name'] . ' — HIMS Voice AI';
$shell_subtitle = 'Post-Call Review · ' . htmlspecialchars($call['patient_name']);
$extra_stylesheets = ['/assets/css/intake.css'];
$body_data_attrs = [
    'data-call-id' => $callId,
    'data-csrf-token' => csrf_token(),
    'data-visit-id' => (int) $call['visit_id'],
    'data-review-status' => $call['review_status'],
];
require __DIR__ . '/../../includes/layout/app-shell-head.php';
?>

<div class="max-w-none mx-auto space-y-4 pb-28">

  <!-- Header -->
  <div class="card p-4 flex items-center justify-between gap-4 flex-wrap">
    <div class="flex items-center gap-3">
      <span class="w-10 h-10 rounded-full bg-[var(--surface-2)] text-[var(--primary)] flex items-center justify-center">
        <?= icon('user', 'w-5 h-5') ?>
      </span>
      <div class="leading-tight">
        <p class="font-semibold text-sm"><?= htmlspecialchars($call['patient_name']) ?></p>
        <p class="text-[11px] text-[var(--text-muted)]">
          <?= (int) $call['patient_age'] ?> yrs · <?= htmlspecialchars($call['patient_gender']) ?> ·
          <?= htmlspecialchars($call['uhid']) ?> · AI phone intake ·
          <?= $call['started_at'] && $call['ended_at']
              ? gmdate('i:s', max(0, strtotime($call['ended_at']) - strtotime($call['started_at'])))
              : '—' ?>
        </p>
      </div>
    </div>
    <span id="review-status-badge" class="state-badge <?= $call['review_status'] === 'approved' ? 'state-completed' : ($call['review_status'] === 'discarded' ? 'state-ready' : 'state-processing') ?>">
      <?= $call['review_status'] === 'approved' ? 'Approved' : ($call['review_status'] === 'discarded' ? 'Discarded' : 'Draft — not in the record yet') ?>
    </span>
  </div>

  <div class="intake-columns">

    <!-- LEFT: transcript scrubber + jump-to + previous visits -->
    <div class="space-y-4">
      <div class="card p-4">
        <h2 class="font-semibold text-sm mb-3">Call transcript</h2>
        <p class="text-[11px] text-[var(--text-muted)] mb-2">No audio recording in this mocked call — markers jump to transcript lines.</p>
        <div class="scrubber-track mb-3">
          <?php
            $total = max(1, count($transcript));
            foreach ($transcript as $i => $turn) {
                $isFlagged = false;
                foreach ($redFlags as $flag) {
                    if (!empty($flag['evidence']) && stripos($turn['text'], substr($flag['evidence'], 0, 20)) !== false) {
                        $isFlagged = true;
                    }
                }
                if (!$isFlagged) continue;
                $pct = round(($i / $total) * 100, 1);
                echo '<span class="scrubber-marker is-red" style="left:' . $pct . '%" data-jump="' . (int) $i . '" title="Red flag"></span>';
            }
          ?>
        </div>
        <div id="review-transcript" class="transcript-box" style="height: 280px;">
          <?php foreach ($transcript as $i => $turn): ?>
            <p id="t-line-<?= (int) $i ?>" class="transcript-line">
              <span class="section-tag"><?= htmlspecialchars($turn['step'] ?? '') ?></span>
              <span class="transcript-speaker <?= $turn['speaker'] === 'Agent' ? 'text-[var(--primary)]' : 'text-[var(--accent-green)]' ?>"><?= htmlspecialchars($turn['speaker']) ?>:</span>
              <?= htmlspecialchars($turn['text']) ?>
            </p>
          <?php endforeach; ?>
        </div>
      </div>

      <?php if ($previousVisits): ?>
        <div class="card p-4">
          <h2 class="font-semibold text-sm mb-3">Previous visits</h2>
          <ul class="space-y-2">
            <?php foreach ($previousVisits as $v): ?>
              <li class="flex items-center justify-between gap-2 text-[12px]">
                <span class="text-[var(--text-muted)]"><?= htmlspecialchars(date('d M Y', strtotime($v['visit_datetime']))) ?> · Dr. <?= htmlspecialchars($v['doctor_name']) ?></span>
                <a href="/consultation/print.php?visit_id=<?= (int) $v['id'] ?>" target="_blank" class="btn-secondary px-2.5 py-1 text-[11px] shrink-0">View</a>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>
    </div>

    <!-- CENTER: section cards -->
    <div class="space-y-4">
      <div class="flex items-center justify-between px-1">
        <h2 class="font-semibold text-[15px]">AI Intake Draft</h2>
        <span class="ai-tag"><?= icon('sparkles', 'w-3.5 h-3.5') ?> Auto-filled — review before saving</span>
      </div>

      <?php foreach (['chief_complaint', 'history_of_present_illness', 'current_medication'] as $key): ?>
        <?php
          $s = $state[$key] ?? ['text' => '', 'confidence' => 0.0];
          $conf = (float) ($s['confidence'] ?? 0.0);
          $level = confidenceLevelPhp($conf);
          $meta = $SECTION_TITLES[$key];
        ?>
        <article class="card ai-field p-5 sm:p-6" data-section="<?= htmlspecialchars($meta['label']) ?>">
          <div class="field-head">
            <div class="flex items-center gap-2">
              <span class="section-num"><?= $meta['num'] ?></span>
              <h3 class="font-semibold text-sm"><?= htmlspecialchars($meta['label']) ?></h3>
            </div>
            <span class="confidence-pct confidence-<?= $level ?>"><?= round($conf * 100) ?>% <?= $conf > 0 && $conf < 0.5 ? '· verify' : '' ?></span>
          </div>
          <textarea data-intake-field="<?= $key ?>" class="field-textarea" rows="3"><?= htmlspecialchars($s['text'] ?? '') ?></textarea>
          <?php if ($conf > 0 && $conf < 0.5): ?>
            <button type="button" class="btn-secondary px-3 py-1.5 text-[11px] mt-2 confirm-field-btn" data-confirm-field="<?= $key ?>">Confirm this value</button>
          <?php endif; ?>
        </article>
      <?php endforeach; ?>

      <!-- Past & Family History — grouped visually only, still two separate fields underneath -->
      <article class="card ai-field p-5 sm:p-6" data-section="Past &amp; Family History">
        <div class="field-head">
          <div class="flex items-center gap-2">
            <span class="section-num">04</span>
            <h3 class="font-semibold text-sm">Past &amp; Family History</h3>
          </div>
        </div>
        <p class="text-[10px] uppercase tracking-wide text-[var(--text-muted)] mb-1">Past history <span class="confidence-pct confidence-<?= confidenceLevelPhp((float) ($state['past_history']['confidence'] ?? 0)) ?>"><?= round((float) ($state['past_history']['confidence'] ?? 0) * 100) ?>%</span></p>
        <textarea data-intake-field="past_history" class="field-textarea mb-3" rows="2"><?= htmlspecialchars($state['past_history']['text'] ?? '') ?></textarea>
        <p class="text-[10px] uppercase tracking-wide text-[var(--text-muted)] mb-1">Family history <span class="confidence-pct confidence-<?= confidenceLevelPhp((float) ($state['family_history']['confidence'] ?? 0)) ?>"><?= round((float) ($state['family_history']['confidence'] ?? 0) * 100) ?>%</span></p>
        <textarea data-intake-field="family_history" class="field-textarea" rows="2"><?= htmlspecialchars($state['family_history']['text'] ?? '') ?></textarea>
      </article>

      <?php
        $allergyConf = (float) ($state['drug_allergy']['confidence'] ?? 0.0);
        $allergyLevel = confidenceLevelPhp($allergyConf);
      ?>
      <article class="card ai-field p-5 sm:p-6" data-section="Allergies">
        <div class="field-head">
          <div class="flex items-center gap-2">
            <span class="section-num">05</span>
            <h3 class="font-semibold text-sm">Allergies</h3>
          </div>
          <span class="confidence-pct confidence-<?= $allergyLevel ?>"><?= round($allergyConf * 100) ?>%</span>
        </div>
        <textarea data-intake-field="drug_allergy" class="field-textarea" rows="2"><?= htmlspecialchars($state['drug_allergy']['text'] ?? '') ?></textarea>
      </article>

      <article class="card p-5 sm:p-6 opacity-60">
        <div class="field-head">
          <div class="flex items-center gap-2">
            <span class="section-num">06</span>
            <h3 class="font-semibold text-sm">Examination &amp; Diagnosis</h3>
          </div>
        </div>
        <p class="text-[12px] text-[var(--text-muted)]">The agent never fills these — the doctor completes them in person.</p>
      </article>
    </div>

    <!-- RIGHT: triage + tasks + confidence overview + audit trail -->
    <div class="space-y-4">
      <div class="card p-4">
        <h2 class="font-semibold text-sm mb-3">Triage summary</h2>
        <span class="triage-badge severity-<?= $topSeverity ?>">
          <?= icon($topSeverity === 'routine' ? 'check-circle' : 'alert', 'w-4 h-4') ?>
          <?= $topSeverity === 'routine' ? 'Routine — no red flags' : ucfirst($topSeverity) . ' — see today' ?>
        </span>
        <?php if ($redFlags): ?>
          <ul class="mt-3 space-y-1.5">
            <?php foreach ($redFlags as $flag): ?>
              <li class="text-[12px] text-[var(--text-muted)]">
                <strong class="text-[var(--text)]"><?= htmlspecialchars(str_replace('_', ' ', $flag['type'] ?? '')) ?>:</strong>
                <?= htmlspecialchars($flag['evidence'] ?? '') ?>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>

      <div class="card p-4">
        <h2 class="font-semibold text-sm mb-3">Tasks for staff</h2>
        <div id="tasks-list" class="task-checklist">
          <?php if (!$tasks): ?>
            <p class="text-[12px] text-[var(--text-muted)]">No tasks raised.</p>
          <?php endif; ?>
          <?php foreach ($tasks as $i => $task): ?>
            <label class="task-item<?= !empty($task['priority']) && $task['priority'] === 'high' ? ' !border-[var(--accent-red)]' : '' ?>">
              <input type="checkbox" data-task-index="<?= (int) $i ?>" <?= !empty($task['done']) ? 'checked' : '' ?>>
              <span><?= htmlspecialchars($task['text'] ?? '') ?>
                <span class="task-source"><?= $task['source'] === 'red_flag' ? 'AI suggestion · needs doctor approval' : 'low-confidence field follow-up' ?></span>
              </span>
            </label>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="card p-4">
        <h2 class="font-semibold text-sm mb-3">Confidence overview</h2>
        <div id="confidence-overview" class="space-y-3"></div>
        <p class="text-[11px] text-[var(--text-muted)] mt-2">Anything under 50% is marked "verify" and worth a second look before approving.</p>
      </div>

      <?php if ($usage): ?>
        <div class="card p-4">
          <div class="flex items-center justify-between mb-3">
            <h2 class="font-semibold text-sm">Final usage</h2>
            <span class="text-[11px] text-[var(--accent-green)]">final</span>
          </div>
          <div class="usage-grid">
            <span>Total API calls</span><strong><?= number_format((int) ($usage['api_calls'] ?? 0)) ?></strong>
            <span>LLM calls</span><strong><?= number_format((int) ($usage['llm_api_calls'] ?? $usage['api_calls'] ?? 0)) ?></strong>
            <span>STT calls</span><strong><?= number_format((int) ($usage['stt_api_calls'] ?? 0)) ?></strong>
            <span>Input tokens</span><strong><?= number_format((int) ($usage['input_tokens'] ?? 0)) ?></strong>
            <span>Output tokens</span><strong><?= number_format((int) ($usage['output_tokens'] ?? 0)) ?></strong>
            <span>Total tokens</span><strong><?= number_format((int) ($usage['total_tokens'] ?? 0)) ?></strong>
            <span>LLM time</span><strong><?= number_format((float) ($usage['total_time_seconds'] ?? 0), 1) ?>s</strong>
          </div>
          <p class="text-[11px] text-[var(--text-muted)] mt-2">
            <?= htmlspecialchars(($usage['provider'] ?? 'LLM') . ' · ' . ($usage['model'] ?? '') . ' · estimated tokens') ?>
          </p>
        </div>
      <?php endif; ?>

      <div class="card p-4">
        <h2 class="font-semibold text-sm mb-3">Audit trail</h2>
        <?php if (!$events): ?>
          <p class="text-[12px] text-[var(--text-muted)]">No events logged.</p>
        <?php endif; ?>
        <?php foreach ($events as $ev): ?>
          <p class="audit-trail-item">
            <span class="audit-time"><?= htmlspecialchars(date('H:i', strtotime($ev['created_at']))) ?></span>
            <?= htmlspecialchars(str_replace('_', ' ', $ev['event_type'])) ?>
          </p>
        <?php endforeach; ?>
      </div>
    </div>

  </div>
</div>

<!-- Action bar -->
<div class="fixed bottom-0 inset-x-0 z-30 border-t border-[var(--border)] bg-[var(--surface)]/95 backdrop-blur-md">
  <div class="max-w-none mx-auto px-4 py-3 flex items-center gap-2 flex-wrap">
    <button type="button" id="btn-save-draft" class="action-btn action-btn-ghost"><?= icon('save', 'w-4.5 h-4.5') ?> <span>Save Draft</span></button>
    <button type="button" id="btn-approve" class="action-btn action-btn-primary"><?= icon('check-circle', 'w-4.5 h-4.5') ?> <span>Approve &amp; attach to visit</span></button>
    <a id="btn-print" href="<?= $call['visit_id'] ? '/consultation/print.php?visit_id=' . (int) $call['visit_id'] . '&autoprint=1' : '#' ?>" target="_blank"
       class="action-btn action-btn-ghost<?= $call['visit_id'] ? '' : ' pointer-events-none opacity-40' ?>"><?= icon('printer', 'w-4.5 h-4.5') ?> <span>Print Prescription</span></a>
    <a id="btn-pdf" href="<?= $call['visit_id'] ? '/consultation/print.php?visit_id=' . (int) $call['visit_id'] . '&autoprint=1' : '#' ?>" target="_blank"
       class="action-btn action-btn-ghost<?= $call['visit_id'] ? '' : ' pointer-events-none opacity-40' ?>"><?= icon('download', 'w-4.5 h-4.5') ?> <span>Generate PDF</span></a>
    <button type="button" id="btn-sms" class="action-btn action-btn-ghost"><?= icon('phone', 'w-4.5 h-4.5') ?> <span>Send SMS</span></button>
    <div class="flex-1"></div>
    <button type="button" id="btn-discard" class="action-btn action-btn-ghost !text-[var(--accent-red)]"><?= icon('trash', 'w-4.5 h-4.5') ?> <span>Discard AI draft</span></button>
    <a id="btn-next-queue" href="/intake/calls.php" class="action-btn action-btn-cta"><span>Next in review queue</span> <?= icon('arrow-right', 'w-4.5 h-4.5') ?></a>
  </div>
</div>

<script>
  window.__intakeReviewData = {
    state: <?= json_encode($state) ?>,
    tasks: <?= json_encode($tasks) ?>,
    transcript: <?= json_encode($transcript) ?>,
    redFlags: <?= json_encode($redFlags) ?>,
    patientPhone: '',
    sectionTitles: <?= json_encode(array_combine(array_keys($SECTION_TITLES), array_column($SECTION_TITLES, 'label'))) ?>
  };
</script>

<?php
$extra_scripts = [
    '/assets/js/clinical-format.js',
    '/assets/js/intake-review.js',
];
require __DIR__ . '/../../includes/layout/app-shell-foot.php';
?>
