<?php
require __DIR__ . '/../../includes/core/icons.php';
require __DIR__ . '/../../includes/core/auth.php';
require_login();
require __DIR__ . '/../../includes/core/db.php';

$doctor = current_doctor($pdo);
$submitted = isset($_GET['submitted']);

$q = trim($_GET['q'] ?? '');
$patientResults = [];
if ($q !== '' && !$submitted) {
    $stmt = $pdo->prepare('SELECT id, uhid, name, age, gender FROM patients WHERE uhid = ? OR name LIKE ? ORDER BY name');
    $stmt->execute([$q, '%' . $q . '%']);
    $patientResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$page_title = 'Ask the Agent to Call — HIMS Voice AI';
$shell_subtitle = 'Ask the agent to call a patient';
require __DIR__ . '/../../includes/layout/app-shell-head.php';
?>

<div class="max-w-lg mx-auto space-y-4">

  <?php if ($submitted): ?>
    <div class="card p-6 text-center">
      <span class="inline-flex w-12 h-12 rounded-full bg-[var(--accent-green-bg)] text-[var(--accent-green)] items-center justify-center mx-auto mb-3">
        <?= icon('check-circle', 'w-6 h-6') ?>
      </span>
      <h1 class="font-semibold text-sm mb-1">Request submitted</h1>
      <p class="text-[12px] text-[var(--text-muted)] mb-4">
        The agent will introduce itself as an AI assistant calling on your behalf, take consent, and the draft
        will be ready for you to review once it's done. It'll appear in "Doctor requests waiting" on the dashboard.
      </p>
      <a href="/intake/dashboard.php" class="action-btn action-btn-primary inline-flex">Back to dashboard</a>
    </div>
  <?php else: ?>

    <div class="card p-6">
      <h1 class="font-semibold text-sm mb-1 flex items-center gap-2">
        <?= icon('phone', 'w-4 h-4 text-[var(--primary)]') ?> Ask the Agent to Call
      </h1>
      <p class="text-[12px] text-[var(--text-muted)] mb-4">
        Find the patient, then fill in why you'd like the AI intake agent to call them.
      </p>

      <form method="get" class="flex gap-2 mb-4">
        <input type="text" name="q" class="field-input flex-1" value="<?= htmlspecialchars($q) ?>" placeholder="Search patient by UHID or name…">
        <button type="submit" class="px-4 rounded-xl bg-[var(--surface-2)] border border-[var(--border)] text-sm cursor-pointer hover:bg-[var(--border)]">Search</button>
      </form>

      <?php if ($q !== ''): ?>
        <?php if (!$patientResults): ?>
          <p class="text-[13px] text-[var(--text-muted)]">No patient found for "<?= htmlspecialchars($q) ?>".</p>
        <?php else: ?>
          <?php foreach ($patientResults as $p): ?>
            <form method="post" action="/api/intake/request-call.php" class="rounded-xl border border-[var(--border)] p-3 space-y-3 mb-3">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
              <input type="hidden" name="patient_id" value="<?= (int) $p['id'] ?>">
              <p class="text-sm font-medium"><?= htmlspecialchars($p['name']) ?> <span class="text-[11px] text-[var(--text-muted)] font-normal"><?= htmlspecialchars($p['uhid']) ?></span></p>

              <div>
                <label class="text-[10px] uppercase tracking-wide text-[var(--text-muted)] mb-1 block">Why</label>
                <div class="flex flex-wrap gap-3 text-[12px]">
                  <label class="inline-flex items-center gap-1.5 cursor-pointer"><input type="radio" name="purpose" value="Symptom intake" checked> Symptom intake</label>
                  <label class="inline-flex items-center gap-1.5 cursor-pointer"><input type="radio" name="purpose" value="Report follow-up"> Report follow-up</label>
                  <label class="inline-flex items-center gap-1.5 cursor-pointer"><input type="radio" name="purpose" value="Medicine check"> Medicine check</label>
                </div>
              </div>

              <div>
                <label class="text-[10px] uppercase tracking-wide text-[var(--text-muted)] mb-1 block">Anything specific to ask?</label>
                <textarea name="scenario_text" rows="2" class="field-textarea !text-[12px]" placeholder="e.g. ask how many times she vomited and whether the stool was black"></textarea>
              </div>

              <div class="grid grid-cols-2 gap-2">
                <div>
                  <label class="text-[10px] uppercase tracking-wide text-[var(--text-muted)] mb-1 block">When</label>
                  <input type="text" name="preferred_time" class="field-input !py-1.5 !text-[12px]" placeholder="e.g. Before 11:30">
                </div>
                <div>
                  <label class="text-[10px] uppercase tracking-wide text-[var(--text-muted)] mb-1 block">Language</label>
                  <select name="language" class="field-input !py-1.5 !text-[12px]">
                    <option value="en-IN">English</option>
                    <option value="hi-IN">Hindi</option>
                    <option value="gu-IN">Gujarati</option>
                    <option value="mr-IN">Marathi</option>
                  </select>
                </div>
              </div>

              <p class="text-[11px] text-[var(--text-muted)]">
                The agent will introduce itself as an AI assistant calling on your behalf, take consent, and send
                you the draft when it's done.
              </p>

              <button type="submit" class="w-full py-2 rounded-xl bg-gradient-to-b from-[var(--primary)] to-[var(--primary-dark)] text-white text-[13px] font-medium cursor-pointer hover:opacity-95">
                Place the call
              </button>
            </form>
          <?php endforeach; ?>
        <?php endif; ?>
      <?php endif; ?>
    </div>

  <?php endif; ?>
</div>

<?php require __DIR__ . '/../../includes/layout/app-shell-foot.php'; ?>
