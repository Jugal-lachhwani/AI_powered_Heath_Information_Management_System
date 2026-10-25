<!-- ============ CENTER PANEL : AI GENERATED CONSULTATION ============ -->
<section data-panel="consult" class="panel consultation-editor lg:col-span-6 lg:overflow-y-auto lg:overscroll-contain lg:pr-1 lg:pb-2 space-y-4">

  <div class="consultation-section-heading flex items-center justify-between px-1">
    <h2 class="font-semibold text-[15px] flex items-center gap-2">
      <span class="clinical-panel-icon"><?= icon('clipboard', 'w-4.5 h-4.5') ?></span>
      AI Generated Consultation
    </h2>
    <span class="ai-tag">
      <?= icon('sparkles', 'w-3.5 h-3.5') ?> Auto-filled — review before saving
    </span>
  </div>

  <!-- 1. Chief Complaint -->
  <article class="card ai-field p-5 sm:p-6" data-section="Chief Complaint">
    <div class="field-head">
      <div class="flex items-center gap-2">
        <span class="section-num"><?= icon('message', 'w-3.5 h-3.5') ?></span>
        <h3 class="font-semibold text-sm">Chief Complaint</h3>
      </div>
      <span class="ai-tag"><?= icon('sparkles', 'w-3.5 h-3.5') ?> AI generated</span>
    </div>
    <textarea data-ai-field="chief_complaint" class="field-textarea" rows="3" aria-label="Chief Complaint"></textarea>
  </article>

  <!-- 2. History of Present Illness -->
  <article class="card ai-field p-5 sm:p-6" data-section="History of Present Illness">
    <div class="field-head">
      <div class="flex items-center gap-2">
        <span class="section-num"><?= icon('clipboard', 'w-3.5 h-3.5') ?></span>
        <h3 class="font-semibold text-sm">History of Present Illness</h3>
      </div>
      <span class="ai-tag"><?= icon('sparkles', 'w-3.5 h-3.5') ?> AI generated</span>
    </div>
    <textarea data-ai-field="history_of_present_illness" class="field-textarea" rows="4" aria-label="History of Present Illness"></textarea>
  </article>

  <!-- 3. Past Medical History -->
  <article class="card ai-field p-5 sm:p-6" data-section="Past Medical History">
    <div class="field-head">
      <div class="flex items-center gap-2">
        <span class="section-num"><?= icon('heart-pulse', 'w-3.5 h-3.5') ?></span>
        <h3 class="font-semibold text-sm">Past Medical History</h3>
      </div>
      <span class="ai-tag"><?= icon('sparkles', 'w-3.5 h-3.5') ?> AI generated</span>
    </div>
    <div id="pmh-chips" data-ai-field="past_history" class="chip-list mb-3"></div>
    <div class="flex gap-2">
      <input id="pmh-input" type="text" placeholder="Add condition…" class="field-input flex-1" aria-label="Add past medical history condition">
      <button id="pmh-add" type="button" class="btn-secondary px-3.5" aria-label="Add condition"><?= icon('plus', 'w-4 h-4') ?></button>
    </div>
  </article>

  <!-- 4. Family History -->
  <article class="card ai-field p-5 sm:p-6" data-section="Family History">
    <div class="field-head">
      <div class="flex items-center gap-2">
        <span class="section-num"><?= icon('user', 'w-3.5 h-3.5') ?></span>
        <h3 class="font-semibold text-sm">Family History</h3>
      </div>
      <span class="ai-tag"><?= icon('sparkles', 'w-3.5 h-3.5') ?> AI generated</span>
    </div>
    <textarea data-ai-field="family_history" class="field-textarea" rows="2" aria-label="Family History"></textarea>
  </article>

  <!-- 5. Drug Allergy -->
  <article class="card ai-field p-5 sm:p-6" data-section="Drug Allergy">
    <div class="field-head">
      <div class="flex items-center gap-2">
        <span class="section-num"><?= icon('alert', 'w-3.5 h-3.5') ?></span>
        <h3 class="font-semibold text-sm">Drug Allergy</h3>
      </div>
      <span class="ai-tag"><?= icon('sparkles', 'w-3.5 h-3.5') ?> AI generated</span>
    </div>
    <input data-ai-field="drug_allergy" type="text" class="field-input" value="" aria-label="Drug allergy">
  </article>

  <!-- 6. Examination -->
  <article class="card ai-field p-5 sm:p-6" data-section="Examination">
    <div class="field-head">
      <div class="flex items-center gap-2">
        <span class="section-num"><?= icon('stethoscope', 'w-3.5 h-3.5') ?></span>
        <h3 class="font-semibold text-sm">Examination</h3>
      </div>
      <span class="ai-tag"><?= icon('sparkles', 'w-3.5 h-3.5') ?> AI generated</span>
    </div>

    <?php
      $vital_slots = [
        ['label' => 'BP',     'unit' => 'mmHg', 'icon' => 'activity'],
        ['label' => 'Pulse',  'unit' => 'bpm',  'icon' => 'activity'],
        ['label' => 'Temp',   'unit' => '°F',   'icon' => 'activity'],
        ['label' => 'SpO2',   'unit' => '%',    'icon' => 'activity'],
        ['label' => 'Weight', 'unit' => 'kg',   'icon' => 'activity'],
      ];
    ?>
    <p class="field-label mb-2">Vitals</p>
    <div class="grid grid-cols-2 sm:grid-cols-5 gap-2.5 mb-5">
      <?php foreach ($vital_slots as $v): ?>
        <div class="vital-card" data-vital-card="<?= htmlspecialchars($v['label']) ?>">
          <span class="text-[var(--primary)]"><?= icon($v['icon'], 'w-4 h-4') ?></span>
          <input type="text" value="" class="vital-value" aria-label="<?= htmlspecialchars($v['label']) ?>">
          <p class="vital-label"><?= htmlspecialchars($v['label']) ?> <span class="text-[var(--text-muted)]">· <?= htmlspecialchars($v['unit']) ?></span></p>
        </div>
      <?php endforeach; ?>
    </div>

    <p class="field-label mb-2">General Examination</p>
    <textarea data-ai-field="clinical_examination" class="field-textarea mb-4" rows="2" aria-label="General Examination"></textarea>

    <p class="field-label mb-2">Systemic Examination</p>
    <textarea class="field-textarea" rows="2" aria-label="Systemic Examination"></textarea>
  </article>

  <!-- 7. Diagnosis -->
  <article class="card ai-field p-5 sm:p-6" data-section="Diagnosis">
    <div class="field-head">
      <div class="flex items-center gap-2">
        <span class="section-num"><?= icon('check-circle', 'w-3.5 h-3.5') ?></span>
        <h3 class="font-semibold text-sm">Diagnosis</h3>
      </div>
      <span class="ai-tag"><?= icon('sparkles', 'w-3.5 h-3.5') ?> AI generated</span>
    </div>
    <input data-ai-field="diagnosis" type="text" class="field-input mb-3" value="" aria-label="Diagnosis">
    <p class="field-label mb-2">ICD-10 Suggestions</p>
    <div data-ai-field="icd10" class="chip-list"></div>
  </article>

  <!-- 8. Investigations -->
  <article class="card ai-field p-5 sm:p-6" data-section="Investigations">
    <div class="field-head">
      <div class="flex items-center gap-2">
        <span class="section-num"><?= icon('beaker', 'w-3.5 h-3.5') ?></span>
        <h3 class="font-semibold text-sm">Investigations</h3>
      </div>
      <span class="ai-tag"><?= icon('sparkles', 'w-3.5 h-3.5') ?> AI generated</span>
    </div>
    <div data-ai-field="investigations" class="flex flex-wrap gap-2"></div>
  </article>

  <!-- 9. Prescription -->
  <article class="card ai-field p-5 sm:p-6" data-section="Prescription">
    <div class="field-head">
      <div class="flex items-center gap-2">
        <span class="section-num"><?= icon('clipboard', 'w-3.5 h-3.5') ?></span>
        <h3 class="font-semibold text-sm">Prescription</h3>
      </div>
      <span class="ai-tag"><?= icon('sparkles', 'w-3.5 h-3.5') ?> AI generated</span>
    </div>

    <p class="field-label mb-2">Extracted Prescription Text</p>
    <textarea data-ai-field="prescription" class="field-textarea mb-4" rows="3" aria-label="Extracted Prescription Text"></textarea>

    <div class="overflow-x-auto -mx-1">
      <table class="rx-table" id="rx-table">
        <thead>
          <tr>
            <th>Medicine</th><th>Dose</th><th>Frequency</th><th>Duration</th><th>Remarks</th><th class="w-8"></th>
          </tr>
        </thead>
        <tbody id="rx-table-body"></tbody>
      </table>
    </div>
    <button id="rx-add-row" type="button" class="btn-secondary mt-3 text-xs px-3 py-2">
      <?= icon('plus', 'w-4 h-4') ?> Add Medicine
    </button>
  </article>

  <!-- 10. Advice -->
  <article class="card ai-field p-5 sm:p-6" data-section="Advice">
    <div class="field-head">
      <div class="flex items-center gap-2">
        <span class="section-num"><?= icon('shield', 'w-3.5 h-3.5') ?></span>
        <h3 class="font-semibold text-sm">Advice</h3>
      </div>
      <span class="ai-tag"><?= icon('sparkles', 'w-3.5 h-3.5') ?> AI generated</span>
    </div>
    <ul id="advice-list" data-ai-field="advice" class="space-y-2 mb-3"></ul>
    <div class="flex gap-2">
      <input id="advice-input" type="text" placeholder="Add advice…" class="field-input flex-1" aria-label="Add advice">
      <button id="advice-add" type="button" class="btn-secondary px-3.5" aria-label="Add advice"><?= icon('plus', 'w-4 h-4') ?></button>
    </div>
  </article>

  <!-- 11. Follow-up -->
  <article class="card ai-field p-5 sm:p-6" data-section="Follow-up">
    <div class="field-head">
      <div class="flex items-center gap-2">
        <span class="section-num"><?= icon('calendar', 'w-3.5 h-3.5') ?></span>
        <h3 class="font-semibold text-sm">Follow-up</h3>
      </div>
      <span class="ai-tag"><?= icon('sparkles', 'w-3.5 h-3.5') ?> AI generated</span>
    </div>
    <div class="flex flex-col sm:flex-row gap-3 mb-3">
      <label class="flex items-center gap-2 field-input flex-1 cursor-text">
        <?= icon('calendar', 'w-4 h-4 text-[var(--text-muted)]') ?>
        <input data-ai-field="follow_up_date" type="date" class="bg-transparent outline-none flex-1" value="" aria-label="Follow-up date">
      </label>
      <div class="flex gap-2">
        <button type="button" class="btn-secondary text-xs px-3 quick-followup" data-days="3">+3d</button>
        <button type="button" class="btn-secondary text-xs px-3 quick-followup" data-days="7">+7d</button>
        <button type="button" class="btn-secondary text-xs px-3 quick-followup" data-days="15">+15d</button>
      </div>
    </div>
    <textarea data-ai-field="follow_up" class="field-textarea" rows="2" aria-label="Follow-up note"></textarea>
  </article>

</section>
