<?php
/**
 * /consultation/print.php — Read-only, print-friendly consultation view.
 *
 * Serves two purposes:
 *  - Print/PDF source for the CURRENT visit (opened with ?autoprint=1 by the
 *    bottom bar's Print/Generate PDF buttons, right after auto-saving a draft).
 *  - Read-only viewer for PAST visits (opened via the "Previous Visits" list),
 *    without autoprint — the doctor can still print on demand from here.
 *
 * Reuses the app's own CSS (/assets/css/style.css) and icon set (icons.php) so
 * the printed/PDF output visually matches the on-screen consultation view.
 */
require __DIR__ . '/../../includes/core/icons.php';
require __DIR__ . '/../../includes/core/auth.php';
require __DIR__ . '/../../includes/core/pdf.php';

$visit_id = (int) ($_GET['visit_id'] ?? 0);

// Headless-Chrome PDF rendering (includes/core/pdf.php) has no session cookie, so it
// authenticates via a short-lived signed token scoped to this one visit_id instead.
$tokenAuthorized = isset($_GET['exp'], $_GET['sig'])
    && $visit_id > 0
    && pdf_verify_token($visit_id, (int) $_GET['exp'], (string) $_GET['sig']);

if (!$tokenAuthorized) {
    require_login();
}

require __DIR__ . '/../../includes/core/db.php';

if ($visit_id <= 0) {
    http_response_code(400);
    exit('Invalid visit.');
}

$stmt = $pdo->prepare('
    SELECT p.name, p.age, p.gender, p.uhid,
           v.visit_no, v.visit_datetime,
           d.full_name AS doctor_name,
           c.data AS consultation_data, c.status
    FROM visits v
    JOIN patients p ON p.id = v.patient_id
    JOIN doctors  d ON d.id = v.doctor_id
    LEFT JOIN consultations c ON c.visit_id = v.id
    WHERE v.id = ?
');
$stmt->execute([$visit_id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    http_response_code(404);
    exit('Visit not found.');
}

$data = $row['consultation_data'] ? json_decode($row['consultation_data'], true) : [];
if (!is_array($data)) {
    $data = [];
}

function section_text(array $data, string $key): string
{
    return trim((string) ($data[$key]['text'] ?? ''));
}

function section_items(array $data, string $key): array
{
    $items = $data[$key]['items'] ?? [];
    return is_array($items) ? array_values(array_filter(array_map('trim', $items))) : [];
}

function empty_note(string $label = 'Not recorded'): string
{
    return '<p class="text-[13px] text-[var(--text-muted)] italic">' . htmlspecialchars($label) . '</p>';
}

$visit_dt = new DateTime($row['visit_datetime'], new DateTimeZone('UTC'));
$visit_dt->setTimezone(new DateTimeZone('Asia/Kolkata'));

$vitals = array_values(array_filter($data['clinical_examination']['vitals'] ?? [], fn($v) => !empty($v['value'])));
$medications = $data['prescription']['medications'] ?? [];
$icd10 = $data['diagnosis']['icd10'] ?? [];
$advice = section_items($data, 'advice');
$past_history = section_items($data, 'past_history');
$investigations = section_items($data, 'investigations');
$autoprint = ($_GET['autoprint'] ?? '') === '1';
$status_label = $row['status'] === 'final' ? 'Finalized' : ($row['status'] === 'draft' ? 'Draft' : 'Not yet documented');

$sections = [
    ['num' => '01', 'icon' => 'message', 'title' => 'Chief Complaint'],
    ['num' => '02', 'icon' => 'clock', 'title' => 'History of Present Illness'],
    ['num' => '03', 'icon' => 'clipboard', 'title' => 'Past Medical History'],
    ['num' => '04', 'icon' => 'user', 'title' => 'Family History'],
    ['num' => '05', 'icon' => 'alert', 'title' => 'Drug Allergy'],
    ['num' => '06', 'icon' => 'activity', 'title' => 'Examination'],
    ['num' => '07', 'icon' => 'shield', 'title' => 'Diagnosis'],
    ['num' => '08', 'icon' => 'beaker', 'title' => 'Investigations'],
    ['num' => '09', 'icon' => 'printer', 'title' => 'Prescription'],
    ['num' => '10', 'icon' => 'sparkles', 'title' => 'Advice'],
    ['num' => '11', 'icon' => 'calendar', 'title' => 'Follow-up'],
];
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Consultation — <?= htmlspecialchars($row['name']) ?> — <?= htmlspecialchars($row['visit_no']) ?></title>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Figtree:wght@400;500;600;700;800&family=Noto+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="/assets/css/style.css">

<style>
  body { background: var(--bg); }
  .doc { max-width: 860px; }
  .letterhead-badge { width: 40px; height: 40px; }
  .vitals-grid { display: grid; grid-template-columns: repeat(5, 1fr); gap: .6rem; }
  @media print {
    .no-print { display: none !important; }
    body { background: #fff; padding: 0; }
    .doc { max-width: none; }
    .card { box-shadow: none !important; break-inside: avoid; }
    * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; color-adjust: exact !important; }
  }
  @page { margin: 14mm; }
</style>
</head>
<body class="font-sans text-[var(--text)] antialiased">

<div class="doc mx-auto px-6 py-8 space-y-4">

  <div class="no-print flex justify-end gap-2 mb-2">
    <a href="javascript:history.back()" class="btn-secondary px-3.5 py-2 text-xs">Back</a>
    <button type="button" onclick="window.print()" class="btn-secondary px-3.5 py-2 text-xs">
      <?= icon('printer', 'w-4 h-4') ?> Print
    </button>
  </div>

  <!-- Letterhead -->
  <div class="card p-6">
    <div class="flex items-center gap-2.5 mb-4">
      <div class="letterhead-badge rounded-xl bg-[var(--primary)] text-white flex items-center justify-center shadow-card shrink-0">
        <?= icon('sparkles', 'w-5 h-5') ?>
      </div>
      <div class="leading-tight">
        <p class="font-bold text-[17px] tracking-tight">HIMS <span class="text-[var(--primary)]">Voice AI</span></p>
        <p class="text-[11px] text-[var(--text-muted)] -mt-0.5">Consultation Record</p>
      </div>
      <div class="ml-auto ai-tag"><?= $status_label ?></div>
    </div>

    <div class="flex flex-wrap items-center gap-x-6 gap-y-2 text-[13px] border-t border-[var(--border)] pt-4">
      <span class="flex items-center gap-1.5"><?= icon('user', 'w-4 h-4 text-[var(--primary)]') ?>
        <strong><?= htmlspecialchars($row['name']) ?></strong> · <?= (int) $row['age'] ?> yrs · <?= htmlspecialchars($row['gender']) ?>
      </span>
      <span class="text-[var(--text-muted)]">UHID <?= htmlspecialchars($row['uhid']) ?></span>
      <span class="flex items-center gap-1.5"><?= icon('calendar', 'w-4 h-4 text-[var(--primary)]') ?>
        <?= htmlspecialchars($visit_dt->format('d M Y, h:i A')) ?> IST</span>
      <span class="text-[var(--text-muted)]">Visit <?= htmlspecialchars($row['visit_no']) ?></span>
      <span class="ml-auto font-medium">Dr. <?= htmlspecialchars($row['doctor_name']) ?></span>
    </div>
  </div>

  <?php if (!$row['consultation_data']): ?>

    <div class="card p-6 text-center">
      <?= icon('alert', 'w-6 h-6 text-[var(--accent-amber)] mx-auto mb-2') ?>
      <p class="text-[13px] text-[var(--text-muted)]">No consultation has been documented for this visit yet.</p>
    </div>

  <?php else: ?>

    <!-- 1. Chief Complaint -->
    <article class="card ai-field p-5">
      <div class="field-head"><div class="flex items-center gap-2"><span class="section-num">01</span><h2 class="font-semibold text-sm"><?= icon('message', 'w-4 h-4 inline -mt-0.5 mr-1 text-[var(--primary)]') ?>Chief Complaint</h2></div></div>
      <?= section_text($data, 'chief_complaint') ? '<p class="text-[14px] leading-relaxed">' . nl2br(htmlspecialchars(section_text($data, 'chief_complaint'))) . '</p>' : empty_note() ?>
    </article>

    <!-- 2. HPI -->
    <article class="card ai-field p-5">
      <div class="field-head"><div class="flex items-center gap-2"><span class="section-num">02</span><h2 class="font-semibold text-sm"><?= icon('clock', 'w-4 h-4 inline -mt-0.5 mr-1 text-[var(--primary)]') ?>History of Present Illness</h2></div></div>
      <?= section_text($data, 'history_of_present_illness') ? '<p class="text-[14px] leading-relaxed">' . nl2br(htmlspecialchars(section_text($data, 'history_of_present_illness'))) . '</p>' : empty_note() ?>
    </article>

    <!-- 3. Past History -->
    <article class="card ai-field p-5">
      <div class="field-head"><div class="flex items-center gap-2"><span class="section-num">03</span><h2 class="font-semibold text-sm"><?= icon('clipboard', 'w-4 h-4 inline -mt-0.5 mr-1 text-[var(--primary)]') ?>Past Medical History</h2></div></div>
      <?php if ($past_history): ?>
        <div class="chip-list"><?php foreach ($past_history as $item): ?><span class="chip"><?= htmlspecialchars($item) ?></span><?php endforeach; ?></div>
      <?php else: echo empty_note(); endif; ?>
    </article>

    <!-- 4. Family History -->
    <article class="card ai-field p-5">
      <div class="field-head"><div class="flex items-center gap-2"><span class="section-num">04</span><h2 class="font-semibold text-sm"><?= icon('user', 'w-4 h-4 inline -mt-0.5 mr-1 text-[var(--primary)]') ?>Family History</h2></div></div>
      <?= section_text($data, 'family_history') ? '<p class="text-[14px] leading-relaxed">' . nl2br(htmlspecialchars(section_text($data, 'family_history'))) . '</p>' : empty_note() ?>
    </article>

    <!-- 5. Drug Allergy -->
    <article class="card ai-field p-5">
      <div class="field-head"><div class="flex items-center gap-2"><span class="section-num">05</span><h2 class="font-semibold text-sm"><?= icon('alert', 'w-4 h-4 inline -mt-0.5 mr-1 text-[var(--primary)]') ?>Drug Allergy</h2></div></div>
      <?= section_text($data, 'drug_allergy') ? '<p class="text-[14px] leading-relaxed">' . nl2br(htmlspecialchars(section_text($data, 'drug_allergy'))) . '</p>' : empty_note() ?>
    </article>

    <!-- 6. Examination -->
    <article class="card ai-field p-5">
      <div class="field-head"><div class="flex items-center gap-2"><span class="section-num">06</span><h2 class="font-semibold text-sm"><?= icon('activity', 'w-4 h-4 inline -mt-0.5 mr-1 text-[var(--primary)]') ?>Examination</h2></div></div>
      <?php if ($vitals): ?>
        <p class="field-label mb-2">Vitals</p>
        <div class="vitals-grid mb-4">
          <?php foreach ($vitals as $v): ?>
            <div class="vital-card">
              <span class="text-[var(--primary)]"><?= icon('activity', 'w-4 h-4') ?></span>
              <span class="vital-value"><?= htmlspecialchars($v['value'] ?? '') ?></span>
              <p class="vital-label"><?= htmlspecialchars($v['label'] ?? '') ?> <?= !empty($v['unit']) ? '· ' . htmlspecialchars($v['unit']) : '' ?></p>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <?php if (!empty($data['clinical_examination']['general_examination'])): ?>
        <p class="field-label mb-1">General Examination</p>
        <p class="text-[14px] leading-relaxed mb-3"><?= nl2br(htmlspecialchars($data['clinical_examination']['general_examination'])) ?></p>
      <?php endif; ?>
      <?php if (!empty($data['clinical_examination']['systemic_examination'])): ?>
        <p class="field-label mb-1">Systemic Examination</p>
        <p class="text-[14px] leading-relaxed"><?= nl2br(htmlspecialchars($data['clinical_examination']['systemic_examination'])) ?></p>
      <?php endif; ?>
      <?php if (!$vitals && empty($data['clinical_examination']['general_examination']) && empty($data['clinical_examination']['systemic_examination'])): ?>
        <?= empty_note() ?>
      <?php endif; ?>
    </article>

    <!-- 7. Diagnosis -->
    <article class="card ai-field p-5">
      <div class="field-head"><div class="flex items-center gap-2"><span class="section-num">07</span><h2 class="font-semibold text-sm"><?= icon('shield', 'w-4 h-4 inline -mt-0.5 mr-1 text-[var(--primary)]') ?>Diagnosis</h2></div></div>
      <?= section_text($data, 'diagnosis') ? '<p class="text-[14px] leading-relaxed mb-2">' . nl2br(htmlspecialchars(section_text($data, 'diagnosis'))) . '</p>' : empty_note() ?>
      <?php if ($icd10): ?>
        <div class="chip-list">
          <?php foreach ($icd10 as $code): ?>
            <span class="chip chip-selectable is-selected"><?= icon('check-circle', 'w-3.5 h-3.5') ?> <?= htmlspecialchars(trim(($code['code'] ?? '') . ' — ' . ($code['description'] ?? ''))) ?></span>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </article>

    <!-- 8. Investigations -->
    <article class="card ai-field p-5">
      <div class="field-head"><div class="flex items-center gap-2"><span class="section-num">08</span><h2 class="font-semibold text-sm"><?= icon('beaker', 'w-4 h-4 inline -mt-0.5 mr-1 text-[var(--primary)]') ?>Investigations</h2></div></div>
      <?php if ($investigations): ?>
        <div class="chip-list"><?php foreach ($investigations as $item): ?><span class="chip chip-checkbox is-selected"><?= icon('check-circle', 'w-3.5 h-3.5') ?> <?= htmlspecialchars($item) ?></span><?php endforeach; ?></div>
      <?php else: echo empty_note(); endif; ?>
    </article>

    <!-- 9. Prescription -->
    <article class="card ai-field p-5">
      <div class="field-head"><div class="flex items-center gap-2"><span class="section-num">09</span><h2 class="font-semibold text-sm"><?= icon('printer', 'w-4 h-4 inline -mt-0.5 mr-1 text-[var(--primary)]') ?>Prescription</h2></div></div>
      <?php if ($medications): ?>
        <table class="rx-table">
          <thead><tr><th>Medicine</th><th>Dose</th><th>Frequency</th><th>Duration</th><th>Remarks</th></tr></thead>
          <tbody>
            <?php foreach ($medications as $m): ?>
              <tr>
                <td class="font-medium"><?= htmlspecialchars($m['medicine'] ?? '') ?></td>
                <td><?= htmlspecialchars($m['dose'] ?? '') ?></td>
                <td><?= htmlspecialchars($m['frequency'] ?? '') ?></td>
                <td><?= htmlspecialchars($m['duration'] ?? '') ?></td>
                <td><?= htmlspecialchars(trim((!empty($m['route']) ? 'Route: ' . $m['route'] . '; ' : '') . ($m['remarks'] ?? ''))) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php else: echo empty_note(); endif; ?>
    </article>

    <!-- 10. Advice -->
    <article class="card ai-field p-5">
      <div class="field-head"><div class="flex items-center gap-2"><span class="section-num">10</span><h2 class="font-semibold text-sm"><?= icon('sparkles', 'w-4 h-4 inline -mt-0.5 mr-1 text-[var(--primary)]') ?>Advice</h2></div></div>
      <?php if ($advice): ?>
        <ul class="space-y-2">
          <?php foreach ($advice as $item): ?>
            <li class="advice-item"><span class="w-1.5 h-1.5 rounded-full bg-[var(--primary)] shrink-0 mt-1.5"></span><span class="flex-1"><?= htmlspecialchars($item) ?></span></li>
          <?php endforeach; ?>
        </ul>
      <?php else: echo empty_note(); endif; ?>
    </article>

    <!-- 11. Follow-up -->
    <article class="card ai-field p-5">
      <div class="field-head"><div class="flex items-center gap-2"><span class="section-num">11</span><h2 class="font-semibold text-sm"><?= icon('calendar', 'w-4 h-4 inline -mt-0.5 mr-1 text-[var(--primary)]') ?>Follow-up</h2></div></div>
      <?php if (!empty($data['follow_up_date_iso'])): ?>
        <p class="font-semibold text-[14px] mb-1"><?= htmlspecialchars(date('d M Y', strtotime($data['follow_up_date_iso']))) ?></p>
      <?php endif; ?>
      <?= section_text($data, 'follow_up') ? '<p class="text-[14px] leading-relaxed">' . nl2br(htmlspecialchars(section_text($data, 'follow_up'))) . '</p>' : (empty(($data['follow_up_date_iso'] ?? '')) ? empty_note() : '') ?>
    </article>

    <!-- Signature -->
    <div class="card p-5 flex items-center justify-between">
      <p class="text-[11px] text-[var(--text-muted)]">Generated by HIMS Voice AI — please review before acting on this record.</p>
      <div class="text-right">
        <p class="text-[13px] font-semibold">Dr. <?= htmlspecialchars($row['doctor_name']) ?></p>
        <p class="text-[10px] text-[var(--text-muted)] uppercase tracking-wide">Signature</p>
      </div>
    </div>

  <?php endif; ?>

</div>

<?php if ($autoprint): ?>
<script>window.onload = function () { window.print(); };</script>
<?php endif; ?>
</body>
</html>
