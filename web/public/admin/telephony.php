<?php
require __DIR__ . '/../../includes/core/icons.php';
require __DIR__ . '/../../includes/core/auth.php';
require __DIR__ . '/../../includes/core/db.php';
require_admin($pdo);

$doctor = current_doctor($pdo);

$doctors = $pdo->query('SELECT id, full_name, email, role FROM doctors ORDER BY created_at')->fetchAll(PDO::FETCH_ASSOC);
$adminCount = count(array_filter($doctors, fn($d) => $d['role'] === 'admin'));

$numbers = $pdo->query('SELECT * FROM telephony_numbers ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$settings = $pdo->query('SELECT * FROM telephony_settings WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
$callingWindow = json_decode($settings['calling_window_json'], true) ?: [];
$days = ['mon' => 'Mon', 'tue' => 'Tue', 'wed' => 'Wed', 'thu' => 'Thu', 'fri' => 'Fri', 'sat' => 'Sat', 'sun' => 'Sun'];

$page_title = 'Telephony & Access — HIMS Voice AI';
$shell_subtitle = 'Telephony & access · admin';
$extra_stylesheets = ['/assets/css/intake.css'];
$body_data_attrs = ['data-csrf-token' => csrf_token()];
require __DIR__ . '/../../includes/layout/app-shell-head.php';
?>

<div class="max-w-none mx-auto space-y-4">

  <div class="alert-box">
    <?= icon('alert', 'w-4 h-4 shrink-0') ?>
    <p>Telephony is mocked in this practice module — everything on this page is genuinely stored and
      editable, but <strong>none of it is wired to a real dialer</strong> (no Twilio/Exotel account exists yet).</p>
  </div>

  <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">

    <div class="space-y-4">
      <!-- Numbers -->
      <div class="card p-4">
        <div class="flex items-center justify-between mb-3">
          <h2 class="font-semibold text-sm">Numbers</h2>
        </div>
        <div class="overflow-x-auto mb-3">
          <table class="w-full text-[12.5px]">
            <thead>
              <tr class="text-left text-[10px] uppercase tracking-wide text-[var(--text-muted)] border-b border-[var(--border)]">
                <th class="py-1.5 pr-2">Number</th>
                <th class="py-1.5 pr-2">Used for</th>
                <th class="py-1.5 pr-2">Lines</th>
                <th class="py-1.5 pr-2">Status</th>
                <th class="py-1.5 pr-2"></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($numbers as $n): ?>
                <tr class="border-b border-[var(--border)] last:border-0">
                  <td class="py-1.5 pr-2 font-mono"><?= htmlspecialchars($n['number']) ?></td>
                  <td class="py-1.5 pr-2 text-[var(--text-muted)]"><?= htmlspecialchars($n['used_for']) ?></td>
                  <td class="py-1.5 pr-2"><?= (int) $n['lines'] ?></td>
                  <td class="py-1.5 pr-2"><span class="state-badge <?= $n['status'] === 'active' ? 'state-completed' : 'state-ready' ?>"><?= htmlspecialchars($n['status']) ?></span></td>
                  <td class="py-1.5 pr-2 text-right">
                    <button type="button" class="text-[var(--accent-red)] cursor-pointer" data-delete-number="<?= (int) $n['id'] ?>">✕</button>
                  </td>
                </tr>
              <?php endforeach; ?>
              <?php if (!$numbers): ?>
                <tr><td colspan="5" class="py-2 text-[var(--text-muted)]">No numbers added yet.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
        <form id="add-number-form" class="flex flex-wrap gap-2">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
          <input type="text" name="number" required class="field-input !py-1.5 !text-[12px] flex-1 min-w-[140px]" placeholder="+91 22 4890 1100">
          <input type="text" name="used_for" class="field-input !py-1.5 !text-[12px] flex-1 min-w-[120px]" placeholder="Outbound intake">
          <input type="number" name="lines" value="1" min="1" class="field-input !py-1.5 !text-[12px] w-16">
          <button type="submit" class="btn-secondary px-3 py-1.5 text-[12px]">+ Add number</button>
        </form>
      </div>

      <!-- Calling window -->
      <div class="card p-4">
        <h2 class="font-semibold text-sm mb-1">Calling window</h2>
        <p class="text-[11px] text-[var(--text-muted)] mb-3">Free text per day (e.g. "9-19" or "off") — stored, not enforced by any dialer.</p>
        <form id="calling-window-form" class="grid grid-cols-4 sm:grid-cols-7 gap-2">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
          <?php foreach ($days as $key => $label): ?>
            <div>
              <label class="text-[10px] text-[var(--text-muted)] block mb-1"><?= $label ?></label>
              <input type="text" name="day_<?= $key ?>" value="<?= htmlspecialchars($callingWindow[$key] ?? '') ?>" class="field-input !py-1 !text-[11px] text-center">
            </div>
          <?php endforeach; ?>
          <div class="col-span-4 sm:col-span-7 mt-2">
            <button type="submit" class="btn-secondary px-3 py-1.5 text-[12px]">Save calling window</button>
          </div>
        </form>
      </div>
    </div>

    <div class="space-y-4">
      <!-- Recording & retention -->
      <div class="card p-4">
        <h2 class="font-semibold text-sm mb-3">Recording &amp; retention</h2>
        <form id="retention-form" class="space-y-3">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
          <div class="flex items-center justify-between text-[13px]">
            <label for="retention_days">Keep transcripts for (days)</label>
            <input type="number" id="retention_days" name="retention_days" value="<?= (int) $settings['retention_days'] ?>" min="1" class="field-input !py-1 !text-[12px] w-20">
          </div>
          <label class="flex items-center justify-between text-[13px] cursor-pointer">
            <span>Mask phone numbers in exports</span>
            <input type="checkbox" name="mask_numbers" <?= (int) $settings['mask_numbers'] === 1 ? 'checked' : '' ?>>
          </label>
          <button type="submit" class="btn-secondary px-3 py-1.5 text-[12px]">Save</button>
        </form>
      </div>

      <!-- SMS/WhatsApp sender -->
      <div class="card p-4">
        <h2 class="font-semibold text-sm mb-3">SMS / WhatsApp sender</h2>
        <form id="sms-sender-form" class="space-y-3">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
          <div>
            <label class="text-[11px] uppercase tracking-wide text-[var(--text-muted)] mb-1 block">Sender ID</label>
            <input type="text" name="sms_sender_id" value="<?= htmlspecialchars($settings['sms_sender_id']) ?>" class="field-input !text-[13px]" placeholder="SNRSHP">
          </div>
          <button type="submit" class="btn-secondary px-3 py-1.5 text-[12px]">Save</button>
        </form>
      </div>

      <!-- Doctors & access -->
      <div class="card p-4">
        <h2 class="font-semibold text-sm mb-1">Doctors &amp; access</h2>
        <p class="text-[11px] text-[var(--text-muted)] mb-3">Admins can edit telephony setup and the agent script/guardrails — everyone else is a regular doctor.</p>
        <div class="overflow-x-auto">
          <table class="w-full text-[12.5px]">
            <thead>
              <tr class="text-left text-[10px] uppercase tracking-wide text-[var(--text-muted)] border-b border-[var(--border)]">
                <th class="py-1.5 pr-2">Name</th>
                <th class="py-1.5 pr-2">Email</th>
                <th class="py-1.5 pr-2">Role</th>
                <th class="py-1.5 pr-2"></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($doctors as $d): ?>
                <tr class="border-b border-[var(--border)] last:border-0">
                  <td class="py-1.5 pr-2"><?= htmlspecialchars($d['full_name']) ?><?= (int) $d['id'] === (int) $doctor['id'] ? ' <span class="text-[var(--text-muted)]">(you)</span>' : '' ?></td>
                  <td class="py-1.5 pr-2 text-[var(--text-muted)]"><?= htmlspecialchars($d['email']) ?></td>
                  <td class="py-1.5 pr-2"><span class="state-badge <?= $d['role'] === 'admin' ? 'state-completed' : 'state-ready' ?>"><?= htmlspecialchars($d['role']) ?></span></td>
                  <td class="py-1.5 pr-2 text-right">
                    <?php if ($d['role'] === 'admin' && $adminCount <= 1): ?>
                      <span class="text-[var(--text-muted)] text-[11px]" title="At least one admin must remain">only admin</span>
                    <?php else: ?>
                      <button type="button" class="text-[var(--primary)] cursor-pointer text-[12px]" data-toggle-role="<?= (int) $d['id'] ?>" data-new-role="<?= $d['role'] === 'admin' ? 'doctor' : 'admin' ?>">
                        <?= $d['role'] === 'admin' ? 'Remove admin' : 'Make admin' ?>
                      </button>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
  window.__telephonyAdminCsrf = <?= json_encode(csrf_token()) ?>;
</script>

<?php
$extra_scripts = ['/assets/js/telephony-admin.js'];
require __DIR__ . '/../../includes/layout/app-shell-foot.php';
?>
