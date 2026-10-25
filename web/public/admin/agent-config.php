<?php
require __DIR__ . '/../../includes/core/icons.php';
require __DIR__ . '/../../includes/core/auth.php';
require __DIR__ . '/../../includes/core/db.php';
require_admin($pdo);

$doctor = current_doctor($pdo);

$draftId = get_or_create_draft_script($pdo);
$draftStmt = $pdo->prepare('SELECT * FROM agent_scripts WHERE id = ?');
$draftStmt->execute([$draftId]);
$draft = $draftStmt->fetch(PDO::FETCH_ASSOC);

$stepsStmt = $pdo->prepare('SELECT * FROM agent_script_steps WHERE script_id = ? ORDER BY position');
$stepsStmt->execute([$draftId]);
$steps = $stepsStmt->fetchAll(PDO::FETCH_ASSOC);

$published = $pdo->query("SELECT * FROM agent_scripts WHERE status = 'published' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$agentSettings = $pdo->query('SELECT * FROM agent_settings WHERE id = 1')->fetch(PDO::FETCH_ASSOC);

// Kept in sync by hand with AI_backend/app/prompts/agent_turn.py's GUARDRAILS/EMERGENCY_RULE —
// this is the exact text actually enforced in the turn-generation prompt, not a separate
// description that could drift from what the agent really does. Fixed/read-only this pass;
// see /admin/agent-config.php's docblock and the plan for why only steps + voice are editable.
$GUARDRAILS = [
    'Never gives a diagnosis or a prescription.',
    'Never changes an existing medicine or dose.',
    'Repeats drug names back before recording them.',
    'Ends the call if consent is refused.',
    'Hands off to a human on request, distress, or if the patient is a minor.',
    'Says "I am an AI assistant" whenever asked.',
];
$EMERGENCY_RULE = 'On chest pain, breathlessness, bleeding, stroke signs, or suicidal intent: read a brief '
    . 'emergency advisory, stay on the line, and note that the front desk/doctor has been paged — never end '
    . 'the call or move on as if nothing happened.';

$q = trim($_GET['q'] ?? '');
$patientResults = [];
if ($q !== '') {
    $stmt = $pdo->prepare('SELECT id, uhid, name, age, gender FROM patients WHERE uhid = ? OR name LIKE ? ORDER BY name');
    $stmt->execute([$q, '%' . $q . '%']);
    $patientResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$page_title = 'Agent Configuration — HIMS Voice AI';
$shell_subtitle = 'Agent Configuration · admin';
$extra_stylesheets = ['/assets/css/intake.css'];
$body_data_attrs = ['data-csrf-token' => csrf_token()];
require __DIR__ . '/../../includes/layout/app-shell-head.php';
?>

<div class="max-w-none mx-auto space-y-4">

  <div class="card p-4 flex items-center justify-between gap-4 flex-wrap">
    <div>
      <h1 class="font-semibold text-sm">Agent Script &amp; Voice Configuration</h1>
      <p class="text-[11px] text-[var(--text-muted)] mt-0.5">
        Editing draft v<?= (int) $draft['version'] ?>
        <?php if ($published): ?> · v<?= (int) $published['version'] ?> is live<?php endif; ?>
      </p>
    </div>
    <button type="button" id="btn-publish" class="action-btn action-btn-primary">
      <?= icon('check-circle', 'w-4.5 h-4.5') ?> <span>Publish v<?= (int) $draft['version'] ?></span>
    </button>
  </div>

  <div class="flex gap-1 border-b border-[var(--border)]">
    <button type="button" class="config-tab-btn is-active" data-tab="script">Script</button>
    <button type="button" class="config-tab-btn" data-tab="guardrails">Guardrails</button>
    <button type="button" class="config-tab-btn" data-tab="voice">Voice &amp; Language</button>
    <button type="button" class="config-tab-btn" data-tab="test">Test calls</button>
  </div>

  <!-- ===== Script tab ===== -->
  <div data-tab-panel="script" class="space-y-3">
    <p class="text-[12px] text-[var(--text-muted)]">
      Each step is an <em>instruction</em> to the agent (what to accomplish), not a literal line —
      the actual wording is generated live by the LLM on the call. Locked steps can't be removed.
    </p>
    <div id="steps-list" class="space-y-3">
      <?php foreach ($steps as $i => $step): ?>
        <div class="card p-4" data-step-id="<?= (int) $step['id'] ?>">
          <div class="flex items-center justify-between gap-2 mb-2">
            <div class="flex items-center gap-2">
              <span class="stepper-step is-active"><?= $i + 1 ?></span>
              <input type="text" class="field-input !py-1 !text-[13px] !font-semibold" value="<?= htmlspecialchars($step['title']) ?>" data-field="title">
              <?php if ((int) $step['locked'] === 1): ?>
                <span class="text-[10px] text-[var(--text-muted)]">locked</span>
              <?php endif; ?>
            </div>
            <div class="flex items-center gap-1">
              <button type="button" class="btn-secondary px-2 py-1" data-move="up" title="Move up"><?= icon('chevron-down', 'w-4 h-4 rotate-180') ?></button>
              <button type="button" class="btn-secondary px-2 py-1" data-move="down" title="Move down"><?= icon('chevron-down', 'w-4 h-4') ?></button>
              <?php if ((int) $step['locked'] !== 1): ?>
                <button type="button" class="btn-secondary px-2 py-1 !text-[var(--accent-red)]" data-remove title="Remove step"><?= icon('trash', 'w-4 h-4') ?></button>
              <?php endif; ?>
            </div>
          </div>
          <textarea class="field-textarea !text-[12.5px]" rows="2" data-field="prompt_text" placeholder="What should the agent accomplish this turn?"><?= htmlspecialchars($step['prompt_text']) ?></textarea>
          <div class="mt-2">
            <label class="text-[10px] uppercase tracking-wide text-[var(--text-muted)]">Fills field</label>
            <select class="field-input !py-1 !text-[12px]" data-field="fills_field">
              <option value="">— none —</option>
              <?php foreach (['chief_complaint', 'history_of_present_illness', 'past_history', 'family_history', 'current_medication', 'drug_allergy'] as $f): ?>
                <option value="<?= $f ?>" <?= $step['fills_field'] === $f ? 'selected' : '' ?>><?= str_replace('_', ' ', $f) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <button type="button" id="btn-add-step" class="w-full py-3 rounded-xl border border-dashed border-[var(--border)] text-[13px] text-[var(--text-muted)] hover:bg-[var(--surface-2)] cursor-pointer">
      + Add step
    </button>
  </div>

  <!-- ===== Guardrails tab (read-only) ===== -->
  <div data-tab-panel="guardrails" class="hidden space-y-3">
    <div class="card p-4">
      <h2 class="font-semibold text-sm mb-3">Guardrails · always on</h2>
      <ul class="space-y-2">
        <?php foreach ($GUARDRAILS as $g): ?>
          <li class="flex items-start gap-2 text-[13px]">
            <?= icon('check-circle', 'w-4 h-4 text-[var(--accent-green)] shrink-0 mt-0.5') ?>
            <?= htmlspecialchars($g) ?>
          </li>
        <?php endforeach; ?>
      </ul>
      <p class="text-[11px] text-[var(--text-muted)] mt-3">
        Not editable this pass — these are fixed constraints baked into every agent line the LLM generates
        (see AI_backend/app/prompts/agent_turn.py).
      </p>
    </div>
    <div class="alert-box">
      <?= icon('alert', 'w-4 h-4 shrink-0') ?>
      <p><strong>Emergency rule:</strong> <?= htmlspecialchars($EMERGENCY_RULE) ?></p>
    </div>
  </div>

  <!-- ===== Voice & Language tab ===== -->
  <div data-tab-panel="voice" class="hidden">
    <form id="voice-form" class="card p-4 space-y-4 max-w-xl">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
      <div>
        <label class="text-[11px] uppercase tracking-wide text-[var(--text-muted)] mb-1 block">Voice</label>
        <input type="text" name="persona_voice" class="field-input" value="<?= htmlspecialchars($agentSettings['persona_voice']) ?>">
      </div>
      <div>
        <label class="text-[11px] uppercase tracking-wide text-[var(--text-muted)] mb-1 block">
          Speaking speed <span id="speed-val"><?= $agentSettings['speaking_speed'] ?></span>×
        </label>
        <input type="range" name="speaking_speed" min="0.5" max="1.5" step="0.05" value="<?= $agentSettings['speaking_speed'] ?>" class="w-full" oninput="document.getElementById('speed-val').textContent = this.value">
      </div>
      <div>
        <label class="text-[11px] uppercase tracking-wide text-[var(--text-muted)] mb-1 block">
          Pause before replying <span id="pause-val"><?= $agentSettings['pause_before_replying'] ?></span>s
        </label>
        <input type="range" name="pause_before_replying" min="0" max="2" step="0.1" value="<?= $agentSettings['pause_before_replying'] ?>" class="w-full" oninput="document.getElementById('pause-val').textContent = this.value">
      </div>
      <label class="flex items-center gap-2 text-[13px] cursor-pointer">
        <input type="checkbox" name="allow_interrupt" <?= (int) $agentSettings['allow_interrupt'] === 1 ? 'checked' : '' ?>>
        Let the patient interrupt
      </label>
      <button type="submit" class="action-btn action-btn-primary">Save voice settings</button>
    </form>
  </div>

  <!-- ===== Test calls tab ===== -->
  <div data-tab-panel="test" class="hidden">
    <div class="card p-4 max-w-xl">
      <p class="text-[12px] text-[var(--text-muted)] mb-3">
        Runs a real intake call against this <strong>draft</strong> (v<?= (int) $draft['version'] ?>), even if
        it hasn't been published yet — so you can check your edits before they go live.
      </p>
      <form method="get" class="flex gap-2 mb-4">
        <input type="text" name="q" class="field-input flex-1" value="<?= htmlspecialchars($q) ?>" placeholder="Search patient to test with…">
        <input type="hidden" name="tab" value="test">
        <button type="submit" class="px-4 rounded-xl bg-[var(--surface-2)] border border-[var(--border)] text-sm cursor-pointer hover:bg-[var(--border)]">Search</button>
      </form>
      <?php foreach ($patientResults as $p): ?>
        <form method="post" action="/api/admin/test-agent-script-call.php" class="rounded-xl border border-[var(--border)] p-3 space-y-2 mb-2">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
          <input type="hidden" name="patient_id" value="<?= (int) $p['id'] ?>">
          <p class="text-sm font-medium"><?= htmlspecialchars($p['name']) ?> <span class="text-[11px] text-[var(--text-muted)] font-normal"><?= htmlspecialchars($p['uhid']) ?></span></p>
          <textarea name="scenario_text" rows="2" class="field-textarea !text-[12px]" placeholder="Scenario for the LLM-simulated patient…"></textarea>
          <button type="submit" class="px-3.5 py-2 rounded-xl bg-gradient-to-b from-[var(--primary)] to-[var(--primary-dark)] text-white text-xs font-medium cursor-pointer hover:opacity-95">
            Call me now (test)
          </button>
        </form>
      <?php endforeach; ?>
    </div>
  </div>

</div>

<style>
  .config-tab-btn { padding: .6rem 1rem; font-size: 13px; font-weight: 500; color: var(--text-muted); cursor: pointer; border-bottom: 2px solid transparent; }
  .config-tab-btn.is-active { color: var(--primary); border-bottom-color: var(--primary); }
</style>

<script>
  window.__agentConfigCsrf = <?= json_encode(csrf_token()) ?>;
</script>

<?php
$extra_scripts = ['/assets/js/agent-config.js'];
require __DIR__ . '/../../includes/layout/app-shell-foot.php';
?>
