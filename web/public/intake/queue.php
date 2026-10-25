<?php
require __DIR__ . '/../../includes/core/icons.php';
require __DIR__ . '/../../includes/core/auth.php';
require_login();
require __DIR__ . '/../../includes/core/db.php';

$doctor = current_doctor($pdo);

$published = $pdo->query("SELECT id, version FROM agent_scripts WHERE status = 'published' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);

$who = $_GET['who'] ?? 'all';
$skipNoConsent = ($_GET['skip_no_consent'] ?? '1') === '1';

$whereConsent = $skipNoConsent ? 'AND ai_call_consent = 1' : '';
$previewPatients = [];
if ($who === 'all') {
    $previewPatients = $pdo->query("SELECT id, name, uhid, ai_call_consent FROM patients WHERE 1=1 $whereConsent ORDER BY name LIMIT 30")->fetchAll(PDO::FETCH_ASSOC);
}
$skippedCount = $skipNoConsent
    ? (int) $pdo->query('SELECT COUNT(*) FROM patients WHERE ai_call_consent = 0')->fetchColumn()
    : 0;

$page_title = 'Call Queue — HIMS Voice AI';
$shell_subtitle = 'Call Queue · new batch';
$extra_stylesheets = ['/assets/css/intake.css'];
$body_data_attrs = ['data-csrf-token' => csrf_token()];
require __DIR__ . '/../../includes/layout/app-shell-head.php';
?>

<div class="max-w-none mx-auto space-y-4">

  <?php if (!$published): ?>
    <div class="alert-box">
      <?= icon('alert', 'w-4 h-4 shrink-0') ?>
      <p>No published agent script yet — <a href="/admin/agent-config.php" class="text-[var(--primary)]">publish one</a> before scheduling a batch (calls will fall back to a fixed demo script otherwise).</p>
    </div>
  <?php endif; ?>

  <form method="post" action="/api/intake/schedule-batch.php" class="grid grid-cols-1 lg:grid-cols-2 gap-4">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">

    <div class="space-y-4">
      <!-- 1. Who to call -->
      <div class="card p-4">
        <h2 class="font-semibold text-sm mb-3">1 · Who to call</h2>
        <p class="text-[11px] text-[var(--text-muted)] mb-3">
          This practice module has no appointment scheduler, so "who to call" is either every registered
          patient or a CSV of UHIDs — not a real appointment list.
        </p>
        <label class="flex items-center gap-2 text-[13px] mb-2 cursor-pointer">
          <input type="radio" name="who" value="all" <?= $who === 'all' ? 'checked' : '' ?> data-preview-filter> All patients
        </label>
        <label class="flex items-center gap-2 text-[13px] mb-2 cursor-pointer">
          <input type="radio" name="who" value="csv" <?= $who === 'csv' ? 'checked' : '' ?> data-preview-filter> Upload CSV of UHIDs
        </label>
        <?php if ($who === 'csv'): ?>
          <textarea name="csv_uhids" rows="4" class="field-textarea !text-[12px] mt-2" placeholder="UHID-2026-00001&#10;UHID-2026-00002&#10;…"></textarea>
        <?php endif; ?>
      </div>

      <!-- 2. What the agent should do -->
      <div class="card p-4">
        <h2 class="font-semibold text-sm mb-3">2 · What the agent should do</h2>
        <p class="text-[13px] mb-3">
          Script: <strong><?= $published ? 'v' . (int) $published['version'] . ' (published)' : 'fixed demo script (no published script)' ?></strong>
          — edit in <a href="/admin/agent-config.php" class="text-[var(--primary)]">Agent Configuration</a>.
        </p>
        <label class="text-[11px] uppercase tracking-wide text-[var(--text-muted)] mb-1 block">Language</label>
        <select name="language" class="field-input">
          <option value="en-IN">English</option>
          <option value="hi-IN">Hindi</option>
          <option value="gu-IN">Gujarati</option>
          <option value="mr-IN">Marathi</option>
        </select>
      </div>

      <!-- 3. When -->
      <div class="card p-4">
        <h2 class="font-semibold text-sm mb-3">3 · When</h2>
        <div class="grid grid-cols-2 gap-3 mb-3">
          <div>
            <label class="text-[11px] uppercase tracking-wide text-[var(--text-muted)] mb-1 block">Start</label>
            <input type="datetime-local" name="start_at" class="field-input !text-[13px]">
          </div>
          <div>
            <label class="text-[11px] uppercase tracking-wide text-[var(--text-muted)] mb-1 block">Stop by</label>
            <input type="text" name="stop_by" class="field-input !text-[13px]" placeholder="18:00">
          </div>
        </div>
        <div class="mb-3">
          <label class="text-[11px] uppercase tracking-wide text-[var(--text-muted)] mb-1 block">Calls at a time (illustrative only — see note below)</label>
          <input type="number" name="calls_at_a_time" min="1" max="5" value="2" class="field-input !text-[13px] w-24">
        </div>
        <label class="flex items-center gap-2 text-[13px] mb-2 cursor-pointer">
          <input type="checkbox" name="retry_enabled" checked> Retry if no answer
          <input type="number" name="retry_max" min="0" max="5" value="3" class="field-input !py-1 !text-[12px] w-16 ml-2">
        </label>
        <label class="flex items-center gap-2 text-[13px] cursor-pointer">
          <input type="checkbox" name="skip_no_consent_preview" value="1" <?= $skipNoConsent ? 'checked' : '' ?> data-preview-filter> Skip patients with no consent on file
          <input type="hidden" name="skip_no_consent" value="<?= $skipNoConsent ? '1' : '0' ?>">
        </label>
        <p class="text-[11px] text-[var(--text-muted)] mt-3">
          There's no background dialer in this practice module — scheduling creates queued calls; front
          desk runs them one at a time from the <a href="/intake/dashboard.php" class="text-[var(--primary)]">dashboard</a>,
          the same way doctor-requested calls work. "Calls at a time" isn't enforced.
        </p>
      </div>
    </div>

    <div class="space-y-4">
      <div class="card p-4">
        <div class="flex items-center justify-between mb-3">
          <h2 class="font-semibold text-sm">Preview — <?= count($previewPatients) ?> call<?= count($previewPatients) === 1 ? '' : 's' ?></h2>
        </div>
        <?php if ($who === 'all'): ?>
          <?php if (!$previewPatients): ?>
            <p class="text-[13px] text-[var(--text-muted)]">No patients match.</p>
          <?php else: ?>
            <div class="overflow-x-auto">
              <table class="w-full text-[13px]">
                <thead>
                  <tr class="text-left text-[11px] uppercase tracking-wide text-[var(--text-muted)] border-b border-[var(--border)]">
                    <th class="py-2 pr-3">Patient</th>
                    <th class="py-2 pr-3">UHID</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($previewPatients as $p): ?>
                    <tr class="border-b border-[var(--border)] last:border-0">
                      <td class="py-2 pr-3"><?= htmlspecialchars($p['name']) ?></td>
                      <td class="py-2 pr-3 text-[var(--text-muted)]"><?= htmlspecialchars($p['uhid']) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        <?php else: ?>
          <p class="text-[13px] text-[var(--text-muted)]">Paste UHIDs on the left — matched patients will be scheduled when you submit.</p>
        <?php endif; ?>

        <?php if ($skippedCount): ?>
          <p class="text-[11px] text-[var(--accent-amber)] mt-3"><?= $skippedCount ?> patient(s) skipped — no consent on file.</p>
        <?php endif; ?>
      </div>

      <div class="card p-4 flex items-center justify-between gap-3 flex-wrap">
        <div>
          <p class="text-[11px] text-[var(--text-muted)]">Will be queued</p>
          <p class="text-xl font-bold"><?= $who === 'all' ? count($previewPatients) : '—' ?></p>
        </div>
        <button type="submit" class="action-btn action-btn-cta"><?= icon('phone', 'w-4 h-4') ?> <span>Schedule calls</span></button>
      </div>
    </div>
  </form>

</div>

<script>
  // Changing "who"/"skip consent" reloads this page (GET) to recompute the
  // server-rendered preview — it must NEVER submit the POST form below (that
  // creates real queued calls), so these are handled separately from it.
  document.querySelectorAll('[data-preview-filter]').forEach(function (el) {
    el.addEventListener('change', function () {
      var who = document.querySelector('[name="who"]:checked').value;
      var skip = document.querySelector('[name="skip_no_consent_preview"]').checked ? '1' : '0';
      window.location.href = '/intake/queue.php?who=' + encodeURIComponent(who) + '&skip_no_consent=' + skip;
    });
  });
</script>

<?php require __DIR__ . '/../../includes/layout/app-shell-foot.php'; ?>
