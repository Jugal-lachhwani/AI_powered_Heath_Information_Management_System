<?php
require __DIR__ . '/../../includes/core/icons.php';
require __DIR__ . '/../../includes/core/auth.php';
require_login();
require __DIR__ . '/../../includes/core/db.php';

$doctor = current_doctor($pdo);

$patientId = (int) ($_GET['patient_id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM patients WHERE id = ?');
$stmt->execute([$patientId]);
$patient = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$patient) {
    header('Location: /intake/calls.php');
    exit;
}

$callsStmt = $pdo->prepare("
    SELECT ic.id, ic.status, ic.review_status, ic.call_mode, ic.red_flags, ic.state, ic.language,
           ic.started_at, ic.created_at, d.full_name AS doctor_name
    FROM intake_calls ic
    JOIN doctors d ON d.id = ic.doctor_id
    WHERE ic.patient_id = ?
    ORDER BY ic.created_at DESC
");
$callsStmt->execute([$patientId]);
$calls = $callsStmt->fetchAll(PDO::FETCH_ASSOC);

$visitsStmt = $pdo->prepare('
    SELECT v.id, v.visit_no, v.visit_datetime, d.full_name AS doctor_name, c.status AS consult_status
    FROM visits v
    JOIN doctors d ON d.id = v.doctor_id
    LEFT JOIN consultations c ON c.visit_id = v.id
    WHERE v.patient_id = ?
    ORDER BY v.visit_datetime DESC
');
$visitsStmt->execute([$patientId]);
$visits = $visitsStmt->fetchAll(PDO::FETCH_ASSOC);

$timeline = [];
foreach ($calls as $c) {
    $flags = json_decode($c['red_flags'], true) ?: [];
    $timeline[] = [
        'kind' => 'call', 'date' => $c['created_at'], 'data' => $c, 'has_flag' => (bool) $flags,
    ];
}
foreach ($visits as $v) {
    $timeline[] = ['kind' => 'visit', 'date' => $v['visit_datetime'], 'data' => $v, 'has_flag' => false];
}
usort($timeline, fn($a, $b) => strcmp($b['date'], $a['date']));

// "What the agent has learned" — genuinely derived from past completed calls, not fabricated.
$learned = [];
$lastLanguage = null;
$chiefComplaints = [];
foreach ($calls as $c) {
    if ($c['status'] !== 'completed') continue;
    $state = json_decode($c['state'], true) ?: [];
    if (!$lastLanguage && $c['language']) $lastLanguage = $c['language'];
    $cc = $state['chief_complaint']['text'] ?? '';
    if ($cc) $chiefComplaints[] = $cc;
    if (empty($learned['current_medication']) && !empty($state['current_medication']['text'])) {
        $learned['current_medication'] = $state['current_medication']['text'];
    }
    if (empty($learned['drug_allergy']) && !empty($state['drug_allergy']['text'])) {
        $learned['drug_allergy'] = $state['drug_allergy']['text'];
    }
}

$page_title = 'Patient Timeline — ' . $patient['name'] . ' — HIMS Voice AI';
$shell_subtitle = 'Patient Timeline';
$extra_stylesheets = ['/assets/css/intake.css'];
require __DIR__ . '/../../includes/layout/app-shell-head.php';
?>

<div class="max-w-none mx-auto space-y-4">

  <div class="card p-4 flex items-center justify-between gap-4 flex-wrap">
    <div class="flex items-center gap-3">
      <span class="w-10 h-10 rounded-full bg-[var(--surface-2)] text-[var(--primary)] flex items-center justify-center">
        <?= icon('user', 'w-5 h-5') ?>
      </span>
      <div class="leading-tight">
        <p class="font-semibold text-sm"><?= htmlspecialchars($patient['name']) ?></p>
        <p class="text-[11px] text-[var(--text-muted)]"><?= (int) $patient['age'] ?> yrs · <?= htmlspecialchars($patient['gender']) ?> · <?= htmlspecialchars($patient['uhid']) ?></p>
      </div>
    </div>
    <a href="/intake/request-call.php?q=<?= urlencode($patient['uhid']) ?>" class="action-btn action-btn-primary">
      <?= icon('phone', 'w-4.5 h-4.5') ?> <span>Request an AI call</span>
    </a>
  </div>

  <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">

    <div class="lg:col-span-2 space-y-3">
      <h2 class="font-semibold text-sm px-1">Contact &amp; visit timeline</h2>
      <?php if (!$timeline): ?>
        <div class="card p-4"><p class="text-[13px] text-[var(--text-muted)]">No calls or visits yet.</p></div>
      <?php endif; ?>
      <?php foreach ($timeline as $item): ?>
        <?php if ($item['kind'] === 'call'): ?>
          <?php $c = $item['data']; ?>
          <div class="card p-4<?= $item['has_flag'] ? ' border-l-4 border-l-[var(--accent-red)]' : '' ?>">
            <div class="flex items-center justify-between gap-2 mb-1">
              <p class="text-sm font-medium">
                AI intake call
                <?php if ($item['has_flag']): ?><span class="text-[11px] text-[var(--accent-red)] font-semibold">RED FLAG</span><?php endif; ?>
              </p>
              <span class="text-[11px] text-[var(--text-muted)]"><?= htmlspecialchars($c['created_at']) ?></span>
            </div>
            <p class="text-[12px] text-[var(--text-muted)] mb-2">for Dr. <?= htmlspecialchars($c['doctor_name']) ?> · <?= htmlspecialchars($c['status']) ?> · <?= htmlspecialchars($c['review_status']) ?></p>
            <?php if ($c['status'] === 'completed'): ?>
              <a href="/intake/call-review.php?call_id=<?= (int) $c['id'] ?>" class="btn-secondary px-3 py-1.5 text-[11px]">View draft</a>
            <?php elseif ($c['status'] === 'in_progress'): ?>
              <a href="/intake/call-monitor.php?call_id=<?= (int) $c['id'] ?>" class="btn-secondary px-3 py-1.5 text-[11px]">Monitor</a>
            <?php endif; ?>
          </div>
        <?php else: ?>
          <?php $v = $item['data']; ?>
          <div class="card p-4">
            <div class="flex items-center justify-between gap-2 mb-1">
              <p class="text-sm font-medium">OPD visit · Dr. <?= htmlspecialchars($v['doctor_name']) ?></p>
              <span class="text-[11px] text-[var(--text-muted)]"><?= htmlspecialchars($v['visit_datetime']) ?></span>
            </div>
            <p class="text-[12px] text-[var(--text-muted)] mb-2"><?= htmlspecialchars($v['visit_no']) ?> · <?= htmlspecialchars($v['consult_status'] ?? 'no consultation saved') ?></p>
            <a href="/consultation/print.php?visit_id=<?= (int) $v['id'] ?>" target="_blank" class="btn-secondary px-3 py-1.5 text-[11px]">View</a>
          </div>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>

    <div class="space-y-4">
      <div class="card p-4">
        <h2 class="font-semibold text-sm mb-3">What the agent has learned</h2>
        <?php if (!$learned && !$lastLanguage): ?>
          <p class="text-[12px] text-[var(--text-muted)]">Nothing learned yet — no completed AI calls.</p>
        <?php else: ?>
          <ul class="space-y-2 text-[12px]">
            <?php if ($lastLanguage): ?><li>Prefers <strong><?= htmlspecialchars($lastLanguage) ?></strong></li><?php endif; ?>
            <?php if (!empty($learned['current_medication'])): ?><li>Known medication: <?= htmlspecialchars($learned['current_medication']) ?></li><?php endif; ?>
            <?php if (!empty($learned['drug_allergy'])): ?><li>Known allergy: <?= htmlspecialchars($learned['drug_allergy']) ?></li><?php endif; ?>
          </ul>
          <p class="text-[11px] text-[var(--text-muted)] mt-3">Derived from past completed AI calls only.</p>
        <?php endif; ?>
      </div>

      <div class="card p-4">
        <h2 class="font-semibold text-sm mb-3">Symptom trend</h2>
        <?php if (!$chiefComplaints): ?>
          <p class="text-[12px] text-[var(--text-muted)]">No completed AI calls yet.</p>
        <?php else: ?>
          <ul class="space-y-1.5 text-[12px] text-[var(--text-muted)]">
            <?php foreach (array_slice($chiefComplaints, 0, 3) as $cc): ?>
              <li>· <?= htmlspecialchars($cc) ?></li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>

      <div class="card p-4">
        <h2 class="font-semibold text-sm mb-3">Consent &amp; channels</h2>
        <div class="flex items-center justify-between text-[12px]">
          <span class="text-[var(--text-muted)]">AI voice calls</span>
          <span class="<?= (int) $patient['ai_call_consent'] === 1 ? 'text-[var(--accent-green)]' : 'text-[var(--accent-red)]' ?>">
            <?= (int) $patient['ai_call_consent'] === 1 ? 'consented' : 'not consented' ?>
          </span>
        </div>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../../includes/layout/app-shell-foot.php'; ?>
