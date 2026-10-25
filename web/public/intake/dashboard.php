<?php
require __DIR__ . '/../../includes/core/icons.php';
require __DIR__ . '/../../includes/core/auth.php';
require_login();
require __DIR__ . '/../../includes/core/db.php';

$doctor = current_doctor($pdo);

$callsToday   = (int) $pdo->query("SELECT COUNT(*) FROM intake_calls WHERE date(created_at) = date('now')")->fetchColumn();
$intakeComplete = (int) $pdo->query("SELECT COUNT(*) FROM intake_calls WHERE status = 'completed'")->fetchColumn();
$needsReview  = (int) $pdo->query("SELECT COUNT(*) FROM intake_calls WHERE status = 'completed' AND review_status = 'pending'")->fetchColumn();
$redFlagged   = (int) $pdo->query("SELECT COUNT(*) FROM intake_calls WHERE red_flags != '[]'")->fetchColumn();
$concurrentLines = (int) $pdo->query("SELECT COUNT(*) FROM intake_calls WHERE status = 'in_progress'")->fetchColumn();
$totalCalls   = (int) $pdo->query('SELECT COUNT(*) FROM intake_calls')->fetchColumn();
$consentedCalls = (int) $pdo->query("SELECT COUNT(*) FROM intake_calls WHERE status IN ('in_progress', 'completed')")->fetchColumn();

$liveNow = $pdo->query("
    SELECT ic.id, ic.current_step, ic.started_at, ic.call_mode, p.name AS patient_name, p.age, p.gender,
           d.full_name AS doctor_name
    FROM intake_calls ic
    JOIN patients p ON p.id = ic.patient_id
    JOIN doctors d ON d.id = ic.doctor_id
    WHERE ic.status = 'in_progress'
    ORDER BY ic.started_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

$doctorRequests = $pdo->query("
    SELECT ic.id, ic.purpose, ic.scenario_text, ic.created_at, p.name AS patient_name,
           rd.full_name AS requested_by_name
    FROM intake_calls ic
    JOIN patients p ON p.id = ic.patient_id
    LEFT JOIN doctors rd ON rd.id = ic.requested_by_doctor_id
    WHERE ic.source = 'doctor_requested' AND ic.status = 'queued'
    ORDER BY ic.created_at ASC
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

$filter = $_GET['filter'] ?? 'all';
$where = '1=1';
if ($filter === 'needs_review') $where = "ic.status = 'completed' AND ic.review_status = 'pending'";
elseif ($filter === 'no_answer') $where = "ic.status = 'failed'";
elseif ($filter === 'escalated') $where = "ic.red_flags != '[]'";

$calls = $pdo->query("
    SELECT ic.id, ic.status, ic.review_status, ic.call_mode, ic.script_key, ic.red_flags, ic.created_at,
           p.name AS patient_name, p.uhid, d.full_name AS doctor_name
    FROM intake_calls ic
    JOIN patients p ON p.id = ic.patient_id
    JOIN doctors d ON d.id = ic.doctor_id
    WHERE $where
    ORDER BY ic.created_at DESC
    LIMIT 50
")->fetchAll(PDO::FETCH_ASSOC);

$statusBadge = [
    'queued'      => ['label' => 'Queued', 'class' => 'state-ready'],
    'in_progress' => ['label' => 'In progress', 'class' => 'state-listening'],
    'completed'   => ['label' => 'Completed', 'class' => 'state-completed'],
    'failed'      => ['label' => 'No answer', 'class' => 'state-ready'],
];

$page_title = 'Intake Dashboard — HIMS Voice AI';
$shell_subtitle = 'Front desk · Dr. ' . htmlspecialchars($doctor['full_name'] ?? '');
$extra_stylesheets = ['/assets/css/intake.css'];
require __DIR__ . '/../../includes/layout/app-shell-head.php';
?>

<div class="max-w-none mx-auto space-y-4">

  <div class="flex items-center justify-between flex-wrap gap-2">
    <h1 class="font-semibold text-sm">Front desk overview</h1>
    <a href="/intake/calls.php" class="action-btn action-btn-primary"><?= icon('plus', 'w-4 h-4') ?> <span>New intake call</span></a>
  </div>

  <!-- Stat tiles -->
  <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
    <div class="card p-4">
      <p class="text-[10px] uppercase tracking-wide text-[var(--text-muted)]">Calls today</p>
      <p class="text-2xl font-bold mt-1"><?= $callsToday ?></p>
    </div>
    <div class="card p-4">
      <p class="text-[10px] uppercase tracking-wide text-[var(--text-muted)]">Intake complete</p>
      <p class="text-2xl font-bold mt-1 text-[var(--accent-green)]"><?= $intakeComplete ?></p>
    </div>
    <div class="card p-4">
      <p class="text-[10px] uppercase tracking-wide text-[var(--text-muted)]">Needs doctor review</p>
      <p class="text-2xl font-bold mt-1 text-[var(--accent-amber)]"><?= $needsReview ?></p>
    </div>
    <div class="card p-4">
      <p class="text-[10px] uppercase tracking-wide text-[var(--text-muted)]">Red flag / escalated</p>
      <p class="text-2xl font-bold mt-1 text-[var(--accent-red)]"><?= $redFlagged ?></p>
    </div>
  </div>

  <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">

    <div class="lg:col-span-2 space-y-4">

      <!-- Live now -->
      <?php if ($liveNow): ?>
        <div class="card p-4">
          <h2 class="font-semibold text-sm mb-3 flex items-center gap-2">
            <span class="w-2 h-2 rounded-full bg-[var(--accent-red)] animate-pulse"></span> Live now
            <span class="text-[11px] text-[var(--text-muted)] font-normal"><?= count($liveNow) ?> in progress</span>
          </h2>
          <div class="space-y-2">
            <?php foreach ($liveNow as $c): ?>
              <div class="flex items-center justify-between gap-3 rounded-xl border border-[var(--border)] p-3">
                <div class="leading-tight">
                  <p class="text-sm font-medium"><?= htmlspecialchars($c['patient_name']) ?> · <?= (int) $c['age'] ?> yrs · <?= htmlspecialchars($c['gender']) ?></p>
                  <p class="text-[11px] text-[var(--text-muted)]">Step: <?= htmlspecialchars(str_replace('_', ' ', $c['current_step'])) ?> · for Dr. <?= htmlspecialchars($c['doctor_name']) ?></p>
                </div>
                <a href="/intake/call-monitor.php?call_id=<?= (int) $c['id'] ?>" class="px-3 py-1.5 rounded-lg bg-[var(--primary)] text-white text-[12px] font-medium hover:opacity-95">Open monitor →</a>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>

      <!-- Calls table -->
      <div class="card p-4">
        <div class="flex items-center justify-between mb-3 flex-wrap gap-2">
          <h2 class="font-semibold text-sm">Recent intake calls</h2>
          <div class="flex gap-1.5 text-[11px]">
            <a href="?filter=all" class="px-2.5 py-1 rounded-full <?= $filter === 'all' ? 'bg-[var(--primary)] text-white' : 'bg-[var(--surface-2)]' ?>">All <?= $totalCalls ?></a>
            <a href="?filter=needs_review" class="px-2.5 py-1 rounded-full <?= $filter === 'needs_review' ? 'bg-[var(--primary)] text-white' : 'bg-[var(--surface-2)]' ?>">Needs review <?= $needsReview ?></a>
            <a href="?filter=escalated" class="px-2.5 py-1 rounded-full <?= $filter === 'escalated' ? 'bg-[var(--primary)] text-white' : 'bg-[var(--surface-2)]' ?>">Escalated <?= $redFlagged ?></a>
          </div>
        </div>
        <?php if (!$calls): ?>
          <p class="text-[13px] text-[var(--text-muted)]">No calls match this filter.</p>
        <?php else: ?>
          <div class="overflow-x-auto">
            <table class="w-full text-[13px]">
              <thead>
                <tr class="text-left text-[11px] uppercase tracking-wide text-[var(--text-muted)] border-b border-[var(--border)]">
                  <th class="py-2 pr-3">Patient</th>
                  <th class="py-2 pr-3">Outcome</th>
                  <th class="py-2 pr-3"></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($calls as $call): ?>
                  <?php
                    $sb = $statusBadge[$call['status']] ?? ['label' => $call['status'], 'class' => 'state-ready'];
                    $isLive = in_array($call['status'], ['queued', 'in_progress'], true);
                    $isDone = $call['status'] === 'completed';
                    $hasFlag = ($call['red_flags'] ?? '[]') !== '[]';
                  ?>
                  <tr class="border-b border-[var(--border)] last:border-0">
                    <td class="py-2.5 pr-3">
                      <p class="font-medium"><?= htmlspecialchars($call['patient_name']) ?></p>
                      <p class="text-[11px] text-[var(--text-muted)]"><?= htmlspecialchars($call['uhid']) ?></p>
                    </td>
                    <td class="py-2.5 pr-3">
                      <span class="state-badge <?= $hasFlag ? 'state-listening' : $sb['class'] ?>"><?= $hasFlag ? 'Red flag' : $sb['label'] ?></span>
                    </td>
                    <td class="py-2.5 pr-3 text-right">
                      <?php if ($isLive): ?>
                        <a href="/intake/call-monitor.php?call_id=<?= (int) $call['id'] ?>" class="px-3 py-1.5 rounded-lg bg-[var(--primary)] text-white text-[12px] font-medium">Monitor</a>
                      <?php elseif ($isDone): ?>
                        <a href="/intake/call-review.php?call_id=<?= (int) $call['id'] ?>" class="px-3 py-1.5 rounded-lg bg-[var(--surface-2)] border border-[var(--border)] text-[12px] font-medium">Review</a>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="space-y-4">

      <!-- Doctor requests waiting -->
      <div class="card p-4">
        <h2 class="font-semibold text-sm mb-3">Doctor requests waiting</h2>
        <?php if (!$doctorRequests): ?>
          <p class="text-[12px] text-[var(--text-muted)]">No pending requests.</p>
        <?php else: ?>
          <div class="space-y-2">
            <?php foreach ($doctorRequests as $r): ?>
              <div class="rounded-xl border border-[var(--border)] p-3">
                <p class="text-sm font-medium"><?= htmlspecialchars($r['patient_name']) ?></p>
                <p class="text-[11px] text-[var(--text-muted)] mb-2">
                  Dr. <?= htmlspecialchars($r['requested_by_name'] ?? '—') ?>
                  <?= $r['purpose'] ? '· ' . htmlspecialchars($r['purpose']) : '' ?>
                </p>
                <a href="/intake/call-monitor.php?call_id=<?= (int) $r['id'] ?>" class="w-full inline-block text-center py-1.5 rounded-lg bg-[var(--primary)] text-white text-[12px] font-medium">Dial now</a>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <!-- Agent health -->
      <div class="card p-4">
        <h2 class="font-semibold text-sm mb-3">Agent health</h2>
        <dl class="space-y-2 text-[12px]">
          <div class="flex justify-between"><dt class="text-[var(--text-muted)]">Concurrent lines</dt><dd class="font-medium"><?= $concurrentLines ?></dd></div>
          <div class="flex justify-between"><dt class="text-[var(--text-muted)]">Speech latency</dt><dd class="font-medium text-[var(--text-muted)]" title="Mocked telephony — no real call audio to measure">N/A (mocked)</dd></div>
        </dl>
      </div>

      <!-- Consent log -->
      <div class="card p-4">
        <h2 class="font-semibold text-sm mb-2">Consent log</h2>
        <p class="text-[12px] text-[var(--text-muted)]">
          Every call opens with the recording disclosure. <?= $consentedCalls ?> of <?= $totalCalls ?> calls proceeded past that step.
        </p>
      </div>

    </div>
  </div>
</div>

<?php require __DIR__ . '/../../includes/layout/app-shell-foot.php'; ?>
