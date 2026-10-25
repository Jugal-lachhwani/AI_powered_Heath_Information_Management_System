<?php
require __DIR__ . '/../../includes/core/icons.php';
require __DIR__ . '/../../includes/core/auth.php';
require_login();
require __DIR__ . '/../../includes/core/db.php';

$doctor = current_doctor($pdo);
$range = $_GET['range'] ?? '30';
$days = in_array($range, ['7', '30'], true) ? (int) $range : 30;

$totalCalls = (int) $pdo->query('SELECT COUNT(*) FROM intake_calls')->fetchColumn();
$completedCalls = (int) $pdo->query("SELECT COUNT(*) FROM intake_calls WHERE status = 'completed'")->fetchColumn();
$completedPct = $totalCalls ? round($completedCalls / $totalCalls * 100) : 0;

$approvedRows = $pdo->query("
    SELECT state, ai_original_state FROM intake_calls
    WHERE review_status = 'approved' AND ai_original_state != ''
")->fetchAll(PDO::FETCH_ASSOC);

$SECTION_KEYS = ['chief_complaint', 'history_of_present_illness', 'past_history', 'family_history', 'current_medication', 'drug_allergy'];
$editedFieldCounts = array_fill_keys($SECTION_KEYS, 0);
$unchangedApproved = 0;
$anyEditedCount = 0;
foreach ($approvedRows as $row) {
    $current = json_decode($row['state'], true) ?: [];
    $original = json_decode($row['ai_original_state'], true) ?: [];
    $rowEdited = false;
    foreach ($SECTION_KEYS as $key) {
        $curText = $current[$key]['text'] ?? '';
        $origText = $original[$key]['text'] ?? '';
        if ($curText !== $origText) {
            $editedFieldCounts[$key]++;
            $rowEdited = true;
        }
    }
    if ($rowEdited) $anyEditedCount++;
    else $unchangedApproved++;
}
$editRate = count($approvedRows) ? round($anyEditedCount / count($approvedRows) * 100) : 0;
$clinicMinutesSaved = $completedCalls * 4; // illustrative estimate — ~4 min front-desk time per completed call

$callsPerDay = $pdo->query("
    SELECT date(created_at) AS d,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed,
        SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS no_answer,
        SUM(CASE WHEN red_flags != '[]' THEN 1 ELSE 0 END) AS escalated,
        COUNT(*) AS total
    FROM intake_calls
    WHERE date(created_at) >= date('now', '-$days days')
    GROUP BY date(created_at)
    ORDER BY d
")->fetchAll(PDO::FETCH_ASSOC);
$maxDayTotal = max(1, ...array_map(fn($r) => (int) $r['total'], $callsPerDay ?: [['total' => 0]]));

$funnel = [
    'Answered' => (int) $pdo->query("SELECT COUNT(*) FROM intake_calls WHERE status IN ('in_progress','completed')")->fetchColumn(),
    'Complaint captured' => (int) $pdo->query("SELECT COUNT(*) FROM intake_calls WHERE json_extract(state, '$.chief_complaint.text') != '' AND json_extract(state, '$.chief_complaint.text') IS NOT NULL")->fetchColumn(),
    'Full intake done' => $completedCalls,
    'Approved unchanged' => $unchangedApproved,
];
$funnelMax = max(1, ...array_values($funnel));

$languageMix = $pdo->query('SELECT language, COUNT(*) AS n FROM intake_calls GROUP BY language ORDER BY n DESC')->fetchAll(PDO::FETCH_ASSOC);
$languageTotal = max(1, array_sum(array_column($languageMix, 'n')));

$redFlagRows = $pdo->query("SELECT red_flags FROM intake_calls WHERE red_flags != '[]'")->fetchAll(PDO::FETCH_ASSOC);
$redFlagCounts = [];
foreach ($redFlagRows as $r) {
    foreach (json_decode($r['red_flags'], true) ?: [] as $flag) {
        $t = $flag['type'] ?? 'unknown';
        $redFlagCounts[$t] = ($redFlagCounts[$t] ?? 0) + 1;
    }
}
arsort($redFlagCounts);

$page_title = 'Intake Analytics — HIMS Voice AI';
$shell_subtitle = 'Reports';
$extra_stylesheets = ['/assets/css/intake.css'];
require __DIR__ . '/../../includes/layout/app-shell-head.php';
?>

<div class="max-w-none mx-auto space-y-4">

  <div class="flex items-center justify-between flex-wrap gap-2">
    <h1 class="font-semibold text-sm">Is the agent actually saving clinic time?</h1>
    <div class="flex gap-1.5 text-[12px]">
      <a href="?range=7" class="px-2.5 py-1 rounded-full <?= $days === 7 ? 'bg-[var(--primary)] text-white' : 'bg-[var(--surface-2)]' ?>">7 days</a>
      <a href="?range=30" class="px-2.5 py-1 rounded-full <?= $days === 30 ? 'bg-[var(--primary)] text-white' : 'bg-[var(--surface-2)]' ?>">30 days</a>
    </div>
  </div>

  <!-- Stat tiles -->
  <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
    <div class="card p-4">
      <p class="text-[10px] uppercase tracking-wide text-[var(--text-muted)]">Calls placed</p>
      <p class="text-2xl font-bold mt-1"><?= $totalCalls ?></p>
    </div>
    <div class="card p-4">
      <p class="text-[10px] uppercase tracking-wide text-[var(--text-muted)]">Intake completed</p>
      <p class="text-2xl font-bold mt-1"><?= $completedPct ?>%</p>
      <p class="text-[11px] text-[var(--text-muted)]"><?= $completedCalls ?> of <?= $totalCalls ?></p>
    </div>
    <div class="card p-4">
      <p class="text-[10px] uppercase tracking-wide text-[var(--text-muted)]">Doctor edit rate</p>
      <p class="text-2xl font-bold mt-1"><?= $editRate ?>%</p>
      <p class="text-[11px] text-[var(--text-muted)]">of <?= count($approvedRows) ?> approved drafts</p>
    </div>
    <div class="card p-4">
      <p class="text-[10px] uppercase tracking-wide text-[var(--text-muted)]">Clinic min. saved</p>
      <p class="text-2xl font-bold mt-1"><?= number_format($clinicMinutesSaved) ?></p>
      <p class="text-[11px] text-[var(--text-muted)]" title="Illustrative estimate: ~4 min per completed call">≈ 4 min/consult (est.)</p>
    </div>
  </div>

  <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">

    <!-- Calls per day -->
    <div class="card p-4">
      <div class="flex items-center justify-between mb-3">
        <h2 class="font-semibold text-sm">Calls per day</h2>
        <div class="flex items-center gap-3 text-[11px] text-[var(--text-muted)]">
          <span class="inline-flex items-center gap-1"><span class="w-2.5 h-2.5 rounded-sm" style="background:var(--accent-green)"></span>completed</span>
          <span class="inline-flex items-center gap-1"><span class="w-2.5 h-2.5 rounded-sm" style="background:var(--surface-2);border:1px solid var(--border)"></span>no answer</span>
          <span class="inline-flex items-center gap-1"><span class="w-2.5 h-2.5 rounded-sm" style="background:var(--accent-red)"></span>escalated</span>
        </div>
      </div>
      <?php if (!$callsPerDay): ?>
        <p class="text-[13px] text-[var(--text-muted)]">No calls in this range.</p>
      <?php else: ?>
        <div class="flex items-end gap-1" style="height:140px;">
          <?php foreach ($callsPerDay as $d): ?>
            <?php
              $completedH = round((int) $d['completed'] / $maxDayTotal * 100);
              $noAnswerH = round((int) $d['no_answer'] / $maxDayTotal * 100);
              $escalatedH = round((int) $d['escalated'] / $maxDayTotal * 100);
            ?>
            <div class="flex-1 flex flex-col-reverse rounded-sm overflow-hidden" style="height:100%"
                 title="<?= htmlspecialchars($d['d']) ?>: <?= (int) $d['total'] ?> call(s)">
              <?php if ($completedH): ?><div style="height:<?= $completedH ?>%;background:var(--accent-green)"></div><?php endif; ?>
              <?php if ($noAnswerH): ?><div style="height:<?= $noAnswerH ?>%;background:var(--surface-2);border-top:1px solid var(--border)"></div><?php endif; ?>
              <?php if ($escalatedH): ?><div style="height:<?= $escalatedH ?>%;background:var(--accent-red)"></div><?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
        <div class="flex justify-between text-[10px] text-[var(--text-muted)] mt-1">
          <span><?= htmlspecialchars($callsPerDay[0]['d']) ?></span>
          <span><?= htmlspecialchars(end($callsPerDay)['d']) ?></span>
        </div>
      <?php endif; ?>
    </div>

    <!-- Where calls drop off -->
    <div class="card p-4">
      <h2 class="font-semibold text-sm mb-3">Where calls drop off</h2>
      <div class="space-y-2.5">
        <?php foreach ($funnel as $label => $count): ?>
          <div>
            <div class="flex justify-between text-[12px] mb-1"><span><?= htmlspecialchars($label) ?></span><span class="font-medium"><?= $count ?></span></div>
            <div class="confidence-track"><div class="h-full rounded-full" style="width:<?= round($count / $funnelMax * 100) ?>%;background:var(--primary)"></div></div>
          </div>
        <?php endforeach; ?>
      </div>
      <p class="text-[11px] text-[var(--text-muted)] mt-3">Biggest loss is usually at approval — check "Most edited fields" below.</p>
    </div>

    <!-- Most edited fields -->
    <div class="card p-4">
      <h2 class="font-semibold text-sm mb-3">Most edited fields</h2>
      <?php if (!$approvedRows): ?>
        <p class="text-[13px] text-[var(--text-muted)]">No approved drafts yet.</p>
      <?php else: ?>
        <div class="space-y-2.5">
          <?php
            arsort($editedFieldCounts);
            foreach ($editedFieldCounts as $key => $count):
              $pct = round($count / count($approvedRows) * 100);
          ?>
            <div>
              <div class="flex justify-between text-[12px] mb-1"><span><?= str_replace('_', ' ', $key) ?></span><span class="font-medium"><?= $pct ?>%</span></div>
              <div class="confidence-track"><div class="h-full rounded-full" style="width:<?= $pct ?>%;background:var(--accent-amber)"></div></div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <!-- Language mix + Red flags -->
    <div class="card p-4">
      <h2 class="font-semibold text-sm mb-3">Language mix</h2>
      <?php if (!$languageMix): ?>
        <p class="text-[13px] text-[var(--text-muted)]">No calls yet.</p>
      <?php else: ?>
        <div class="space-y-2 mb-4">
          <?php foreach ($languageMix as $l): ?>
            <div class="flex justify-between text-[12px]">
              <span><?= htmlspecialchars($l['language']) ?></span>
              <span class="text-[var(--text-muted)]"><?= round($l['n'] / $languageTotal * 100) ?>%</span>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <h2 class="font-semibold text-sm mb-3">Red flags raised</h2>
      <?php if (!$redFlagCounts): ?>
        <p class="text-[13px] text-[var(--text-muted)]">No red flags raised yet.</p>
      <?php else: ?>
        <div class="space-y-1.5">
          <?php foreach ($redFlagCounts as $type => $n): ?>
            <div class="flex justify-between text-[12px]">
              <span><?= htmlspecialchars(str_replace('_', ' ', $type)) ?></span>
              <span class="font-medium text-[var(--accent-red)]"><?= $n ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../../includes/layout/app-shell-foot.php'; ?>
