<?php
require __DIR__ . '/../../includes/core/icons.php';
require __DIR__ . '/../../includes/core/auth.php';
require_login();
require __DIR__ . '/../../includes/core/db.php';

$doctor = current_doctor($pdo);
$errors = [];

$q = trim($_GET['q'] ?? '');
$patientResults = [];
if ($q !== '') {
    $stmt = $pdo->prepare('SELECT id, uhid, name, age, gender FROM patients WHERE uhid = ? OR name LIKE ? ORDER BY name');
    $stmt->execute([$q, '%' . $q . '%']);
    $patientResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$calls = $pdo->query('
    SELECT ic.id, ic.script_key, ic.call_mode, ic.status, ic.review_status, ic.current_step, ic.created_at,
           ic.red_flags, p.name AS patient_name, p.uhid, d.full_name AS doctor_name
    FROM intake_calls ic
    JOIN patients p ON p.id = ic.patient_id
    JOIN doctors d ON d.id = ic.doctor_id
    ORDER BY ic.created_at DESC
    LIMIT 50
')->fetchAll(PDO::FETCH_ASSOC);

$hasPublishedScript = (bool) $pdo->query("SELECT id FROM agent_scripts WHERE status = 'published' LIMIT 1")->fetchColumn();

$statusBadge = [
    'queued'      => ['label' => 'Queued', 'class' => 'state-ready'],
    'in_progress' => ['label' => 'In progress', 'class' => 'state-listening'],
    'completed'   => ['label' => 'Completed', 'class' => 'state-completed'],
    'failed'      => ['label' => 'Failed', 'class' => 'state-ready'],
];
$reviewBadge = [
    'pending'   => ['label' => 'Needs review', 'class' => 'text-[var(--accent-amber)]'],
    'approved'  => ['label' => 'Approved', 'class' => 'text-[var(--accent-green)]'],
    'discarded' => ['label' => 'Discarded', 'class' => 'text-[var(--text-muted)]'],
];

$page_title = 'AI Intake Calls — HIMS Voice AI';
$shell_subtitle = 'AI Intake Calls (mocked telephony — practice module)';
require __DIR__ . '/../../includes/layout/app-shell-head.php';
?>

<div class="max-w-none mx-auto space-y-6">

  <?php if ($errors): ?>
    <div class="alert-box">
      <?= icon('alert', 'w-4 h-4 shrink-0') ?>
      <div><?php foreach ($errors as $err): ?><p><?= htmlspecialchars($err) ?></p><?php endforeach; ?></div>
    </div>
  <?php endif; ?>

  <!-- Start a test intake call -->
  <div class="card p-6">
    <h2 class="font-semibold text-sm mb-1 flex items-center gap-2">
      <?= icon('phone', 'w-4 h-4 text-[var(--primary)]') ?> Start a Test Intake Call
    </h2>
    <p class="text-[12px] text-[var(--text-muted)] mb-4">
      Telephony is mocked for this practice module — the AI agent plays a canned script instead of
      placing a real call, but transcription, extraction, and red-flag detection are genuine.
    </p>

    <form method="get" class="flex gap-2 mb-4">
      <input type="text" name="q" class="field-input flex-1" value="<?= htmlspecialchars($q) ?>" placeholder="Search patient by UHID or name…">
      <button type="submit" class="px-4 rounded-xl bg-[var(--surface-2)] border border-[var(--border)] text-sm cursor-pointer hover:bg-[var(--border)]">Search</button>
    </form>

    <?php if (!$hasPublishedScript): ?>
      <div class="alert-box mb-4">
        <?= icon('alert', 'w-4 h-4 shrink-0') ?>
        <?php if (($doctor['role'] ?? 'doctor') === 'admin'): ?>
          <p>No published agent script yet — dynamic calls will fall back to a fixed demo script. <a href="/admin/agent-config.php" class="text-[var(--primary)]">Set one up in Agent Configuration</a>.</p>
        <?php else: ?>
          <p>No published agent script yet — dynamic calls will fall back to a fixed demo script. Ask a clinic admin to set one up in Agent Configuration.</p>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <?php if ($q !== ''): ?>
      <?php if (!$patientResults): ?>
        <p class="text-[13px] text-[var(--text-muted)]">No patient found for "<?= htmlspecialchars($q) ?>". <a href="/patients/select.php" class="text-[var(--primary)]">Register a new patient</a> first.</p>
      <?php else: ?>
        <div class="space-y-3">
          <?php foreach ($patientResults as $p): ?>
            <div class="rounded-xl border border-[var(--border)] p-3">
              <div class="leading-tight mb-2">
                <p class="text-sm font-medium"><?= htmlspecialchars($p['name']) ?></p>
                <p class="text-[11px] text-[var(--text-muted)]"><?= (int) $p['age'] ?> yrs · <?= htmlspecialchars($p['gender']) ?> · <?= htmlspecialchars($p['uhid']) ?></p>
              </div>
              <form method="post" action="/api/intake/start-call.php" class="space-y-2" data-start-call-form>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                <input type="hidden" name="patient_id" value="<?= (int) $p['id'] ?>">

                <div class="flex flex-wrap gap-3 items-center text-[12px]">
                  <label class="inline-flex items-center gap-1.5 cursor-pointer">
                    <input type="radio" name="call_mode" value="dynamic" checked data-call-mode-radio> Dynamic (agent + LLM patient)
                  </label>
                  <label class="inline-flex items-center gap-1.5 cursor-pointer">
                    <input type="radio" name="call_mode" value="fixed" data-call-mode-radio> Fixed demo script
                  </label>
                </div>

                <div data-mode-panel="dynamic">
                  <textarea name="scenario_text" rows="2" class="field-textarea !text-[12px]"
                    placeholder="Scenario for the LLM-simulated patient, e.g. &quot;chest tightness and shortness of breath since this morning&quot;…"></textarea>
                </div>
                <div data-mode-panel="fixed" class="hidden">
                  <select name="script_key" class="field-input !py-1.5 !text-[12px]">
                    <option value="chest_pain_flag">Chest pain (red flag)</option>
                    <option value="routine_follow_up">Routine follow-up</option>
                  </select>
                </div>

                <button type="submit" class="px-3.5 py-2 rounded-xl bg-gradient-to-b from-[var(--primary)] to-[var(--primary-dark)] text-white text-xs font-medium cursor-pointer hover:opacity-95 whitespace-nowrap">
                  + Start test intake call
                </button>
              </form>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <!-- Calls list -->
  <div class="card p-6">
    <h2 class="font-semibold text-sm mb-4 flex items-center gap-2">
      <?= icon('activity', 'w-4 h-4 text-[var(--primary)]') ?> Recent Intake Calls
    </h2>

    <?php if (!$calls): ?>
      <p class="text-[13px] text-[var(--text-muted)]">No intake calls yet — search for a patient above to start one.</p>
    <?php else: ?>

      <!-- Mobile: card list (1i) -->
      <div class="md:hidden space-y-2">
        <?php foreach ($calls as $call): ?>
          <?php
            $sb = $statusBadge[$call['status']] ?? ['label' => htmlspecialchars($call['status']), 'class' => 'state-ready'];
            $rb = $reviewBadge[$call['review_status']] ?? ['label' => htmlspecialchars($call['review_status']), 'class' => ''];
            $isLive = in_array($call['status'], ['queued', 'in_progress'], true);
            $isDone = $call['status'] === 'completed';
            $hasFlag = ($call['red_flags'] ?? '[]') !== '[]';
          ?>
          <div class="rounded-xl border border-[var(--border)] p-3<?= $hasFlag ? ' border-l-4 border-l-[var(--accent-red)]' : '' ?>">
            <div class="flex items-center justify-between gap-2 mb-1">
              <p class="text-sm font-medium"><?= htmlspecialchars($call['patient_name']) ?></p>
              <span class="state-badge <?= $sb['class'] ?>"><?= $sb['label'] ?></span>
            </div>
            <p class="text-[11px] text-[var(--text-muted)] mb-2">
              <?= htmlspecialchars($call['uhid']) ?> · <?= $call['call_mode'] === 'dynamic' ? 'Dynamic' : htmlspecialchars($call['script_key']) ?>
              <span class="<?= $rb['class'] ?>"> · <?= $rb['label'] ?></span>
            </p>
            <?php if ($isLive): ?>
              <a href="/intake/call-monitor.php?call_id=<?= (int) $call['id'] ?>" class="block text-center py-1.5 rounded-lg bg-[var(--primary)] text-white text-[12px] font-medium">Monitor</a>
            <?php elseif ($isDone): ?>
              <a href="/intake/call-review.php?call_id=<?= (int) $call['id'] ?>" class="block text-center py-1.5 rounded-lg bg-[var(--surface-2)] border border-[var(--border)] text-[12px] font-medium">Review</a>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>

      <!-- Desktop: table -->
      <div class="hidden md:block overflow-x-auto">
        <table class="w-full text-[13px]">
          <thead>
            <tr class="text-left text-[11px] uppercase tracking-wide text-[var(--text-muted)] border-b border-[var(--border)]">
              <th class="py-2 pr-3">Patient</th>
              <th class="py-2 pr-3">Mode</th>
              <th class="py-2 pr-3">Status</th>
              <th class="py-2 pr-3">Review</th>
              <th class="py-2 pr-3">Started</th>
              <th class="py-2 pr-3"></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($calls as $call): ?>
              <?php
                $sb = $statusBadge[$call['status']] ?? ['label' => htmlspecialchars($call['status']), 'class' => 'state-ready'];
                $rb = $reviewBadge[$call['review_status']] ?? ['label' => htmlspecialchars($call['review_status']), 'class' => ''];
                $isLive = in_array($call['status'], ['queued', 'in_progress'], true);
                $isDone = $call['status'] === 'completed';
              ?>
              <tr class="border-b border-[var(--border)] last:border-0">
                <td class="py-2.5 pr-3">
                  <p class="font-medium"><?= htmlspecialchars($call['patient_name']) ?></p>
                  <p class="text-[11px] text-[var(--text-muted)]"><?= htmlspecialchars($call['uhid']) ?></p>
                </td>
                <td class="py-2.5 pr-3 text-[var(--text-muted)]">
                  <?= $call['call_mode'] === 'dynamic' ? 'Dynamic' : htmlspecialchars($call['script_key']) ?>
                  <?php if (($call['red_flags'] ?? '[]') !== '[]'): ?>
                    <span class="text-[var(--accent-red)]" title="Red flag(s) detected"><?= icon('alert', 'w-3.5 h-3.5 inline') ?></span>
                  <?php endif; ?>
                </td>
                <td class="py-2.5 pr-3"><span class="state-badge <?= $sb['class'] ?>"><?= $sb['label'] ?></span></td>
                <td class="py-2.5 pr-3 <?= $rb['class'] ?>"><?= $rb['label'] ?></td>
                <td class="py-2.5 pr-3 text-[var(--text-muted)]"><?= htmlspecialchars($call['created_at']) ?></td>
                <td class="py-2.5 pr-3 text-right">
                  <?php if ($isLive): ?>
                    <a href="/intake/call-monitor.php?call_id=<?= (int) $call['id'] ?>" class="px-3 py-1.5 rounded-lg bg-[var(--primary)] text-white text-[12px] font-medium hover:opacity-95">Monitor</a>
                  <?php elseif ($isDone): ?>
                    <a href="/intake/call-review.php?call_id=<?= (int) $call['id'] ?>" class="px-3 py-1.5 rounded-lg bg-[var(--surface-2)] border border-[var(--border)] text-[12px] font-medium hover:bg-[var(--border)]">Review</a>
                  <?php else: ?>
                    <span class="text-[11px] text-[var(--text-muted)]">—</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <div class="text-center">
    <a href="/intake/request-call.php" class="text-[12px] text-[var(--primary)]">+ Ask the agent to call a patient</a>
  </div>

</div>

<script>
  document.querySelectorAll('[data-start-call-form]').forEach(function (form) {
    var radios = form.querySelectorAll('[data-call-mode-radio]');
    function sync() {
      var mode = form.querySelector('[data-call-mode-radio]:checked').value;
      form.querySelectorAll('[data-mode-panel]').forEach(function (panel) {
        panel.classList.toggle('hidden', panel.dataset.modePanel !== mode);
      });
    }
    radios.forEach(function (r) { r.addEventListener('change', sync); });
    sync();
  });
</script>

<?php require __DIR__ . '/../../includes/layout/app-shell-foot.php'; ?>
