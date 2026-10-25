(function () {
  'use strict';

  /* ---------------- Shared clinical-format helpers (/assets/js/clinical-format.js) ---------------- */
  var confidenceLevel = window.HimsClinicalFormat.confidenceLevel;
  var escapeHtml = window.HimsClinicalFormat.escapeHtml;

  /* ---------------- Theme toggle ---------------- */
  var root = document.documentElement;
  var themeBtn = document.getElementById('theme-toggle');

  function reflectTheme() {
    var isDark = root.getAttribute('data-theme') === 'dark';
    themeBtn.querySelector('.theme-icon-light').classList.toggle('hidden', isDark);
    themeBtn.querySelector('.theme-icon-dark').classList.toggle('hidden', !isDark);
  }
  reflectTheme();

  themeBtn.addEventListener('click', function () {
    var next = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
    root.setAttribute('data-theme', next);
    localStorage.setItem('hims-theme', next);
    reflectTheme();
  });

  /* ---------------- Mobile panel tabs ---------------- */
  var tabs = document.querySelectorAll('[data-panel-tab]');
  var panels = document.querySelectorAll('.panel');

  function showPanel(name) {
    panels.forEach(function (p) {
      p.classList.toggle('is-active-mobile', p.dataset.panel === name);
    });
    tabs.forEach(function (t) {
      var active = t.dataset.panelTab === name;
      t.classList.toggle('is-active', active);
      t.setAttribute('aria-selected', active ? 'true' : 'false');
    });
  }
  tabs.forEach(function (t) {
    t.addEventListener('click', function () { showPanel(t.dataset.panelTab); });
  });
  showPanel('record');

  document.getElementById('mobile-panel-menu-btn').addEventListener('click', function () {
    document.querySelector('[data-panel-tab="consult"]').scrollIntoView({ block: 'nearest' });
    showPanel('consult');
  });

  /* ---------------- AI field "reviewed" tracking ---------------- */
  document.querySelectorAll('.ai-field').forEach(function (field) {
    var inputs = field.querySelectorAll('input, textarea, button');
    inputs.forEach(function (el) {
      el.addEventListener('focus', function () { field.dataset.reviewed = 'true'; }, { once: true });
    });
  });

  /* ---------------- Toast ---------------- */
  var toastEl = document.getElementById('toast');
  var toastTimer = null;
  function showToast(msg) {
    if (!msg) return;
    toastEl.textContent = msg;
    toastEl.classList.add('is-visible');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { toastEl.classList.remove('is-visible'); }, 2200);
  }
  document.querySelectorAll('[data-toast]').forEach(function (btn) {
    btn.addEventListener('click', function () { showToast(btn.dataset.toast); });
  });

  /* ---------------- AI clinical extraction ---------------- */
  var aiWsUrl = document.body.dataset.aiWsUrl || 'ws://127.0.0.1:8001/ws/stream';
  var wsSampleRate = Number(document.body.dataset.aiWsSampleRate) || 24000;
  var consultationMode = document.body.dataset.consultationMode || 'direct';
  var visitId = document.body.dataset.visitId || '';
  var csrfToken = document.body.dataset.csrfToken || '';
  var generateBtn = document.getElementById('btn-generate-consultation');
  var generateLabel = document.getElementById('btn-generate-label');
  var generateStatus = document.getElementById('ai-generate-status');
  var suggestionsList = document.getElementById('ai-suggestions-list');
  var usageFinalBadge = document.getElementById('usage-final-badge');
  var usageApiCalls = document.getElementById('usage-api-calls');
  var usageLlmCalls = document.getElementById('usage-llm-calls');
  var usageSttCalls = document.getElementById('usage-stt-calls');
  var usageInputTokens = document.getElementById('usage-input-tokens');
  var usageOutputTokens = document.getElementById('usage-output-tokens');
  var usageTotalTokens = document.getElementById('usage-total-tokens');
  var usageLlmTime = document.getElementById('usage-llm-time');
  var usageNote = document.getElementById('usage-note');
  var lastUsage = null;

  var SECTION_TITLES = {
    chief_complaint: 'Chief Complaint',
    history_of_present_illness: 'History of Present Illness',
    past_history: 'Past History',
    family_history: 'Family History',
    drug_allergy: 'Drug Allergy',
    clinical_examination: 'Clinical Examination',
    diagnosis: 'Diagnosis',
    investigations: 'Investigations',
    prescription: 'Prescription',
    advice: 'Advice',
    follow_up: 'Follow-up',
  };

  function checkIconSvg() {
    return '<svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="8.5"/><path d="M8.5 12.3l2.4 2.4 4.6-5.4"/></svg>';
  }

  function removeIconSvg() {
    return '<svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>';
  }

  function alertIconSvg(className) {
    return '<svg class="' + (className || 'w-4 h-4') + ' shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 4 3 20h18L12 4Z" stroke-linejoin="round"/><path d="M12 10v4.5"/><circle cx="12" cy="17.3" r="0.9" fill="currentColor" stroke="none"/></svg>';
  }

  function chevronIconSvg() {
    return '<svg class="w-4 h-4 text-[var(--text-muted)] chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5.5 8.5 12 15l6.5-6.5"/></svg>';
  }

  function sectionText(section) {
    return section && typeof section.text === 'string' ? section.text.trim() : '';
  }

  function formatNumber(value) {
    return Math.round(Number(value) || 0).toLocaleString('en-IN');
  }

  function renderUsage(usage) {
    if (!usage || !usageApiCalls) return;
    lastUsage = usage;
    usageApiCalls.textContent = formatNumber(usage.api_calls);
    if (usageLlmCalls) usageLlmCalls.textContent = formatNumber(usage.llm_api_calls || usage.api_calls);
    if (usageSttCalls) usageSttCalls.textContent = formatNumber(usage.stt_api_calls);
    usageInputTokens.textContent = formatNumber(usage.input_tokens);
    usageOutputTokens.textContent = formatNumber(usage.output_tokens);
    usageTotalTokens.textContent = formatNumber(usage.total_tokens);
    usageLlmTime.textContent = (Number(usage.total_time_seconds) || 0).toFixed(1) + 's';
    usageFinalBadge.textContent = usage.final ? 'final' : 'running';
    usageFinalBadge.className = usage.final
      ? 'text-[11px] text-[var(--accent-green)]'
      : 'text-[11px] text-[var(--text-muted)]';
    usageNote.textContent = (usage.provider || 'LLM') + ' · ' + (usage.model || '') +
      ' · ' + (usage.calculation === 'estimated_from_text_chars_div_4' ? 'estimated tokens' : 'usage');
  }

  function sectionItems(section) {
    if (section && Array.isArray(section.items) && section.items.length) {
      return section.items
        .map(function (item) { return String(item || '').trim(); })
        .filter(Boolean);
    }
    return splitClinicalList(sectionText(section));
  }

  function sectionDisplayText(section) {
    var text = sectionText(section);
    if (text) return text;
    return sectionItems(section).join('\n');
  }

  function splitClinicalList(text) {
    return String(text || '')
      .split(/\r?\n|;/)
      .map(function (item) { return item.trim(); })
      .filter(Boolean);
  }

  function normalizeVitalLabel(label) {
    var normalized = String(label || '').toLowerCase().replace(/[^a-z0-9]/g, '');
    var map = {
      bloodpressure: 'BP',
      bp: 'BP',
      pulse: 'Pulse',
      heartrate: 'Pulse',
      temperature: 'Temp',
      temp: 'Temp',
      spo2: 'SpO2',
      oxygensaturation: 'SpO2',
      weight: 'Weight',
    };
    return map[normalized] || label;
  }

  function renderVitals(vitals) {
    if (!Array.isArray(vitals)) return;

    vitals.forEach(function (vital) {
      var label = normalizeVitalLabel(vital && vital.label);
      var card = document.querySelector('[data-vital-card="' + label + '"]');
      if (!card) return;

      var input = card.querySelector('.vital-value');
      if (input) {
        input.value = vital.value || '';
        markAiGenerated(input);
      }
    });
  }

  function markAiGenerated(target) {
    var field = target && target.closest ? target.closest('.ai-field') : null;
    if (field) field.removeAttribute('data-reviewed');
  }

  function setTextField(name, value) {
    var field = document.querySelector('[data-ai-field="' + name + '"]');
    if (!field) return;
    field.value = value || '';
    field.dispatchEvent(new Event('input', { bubbles: true }));
    markAiGenerated(field);
  }

  function renderEmpty(container, message) {
    var p = document.createElement('p');
    p.className = 'text-[11px] text-[var(--text-muted)]';
    p.textContent = message;
    container.appendChild(p);
  }

  function createRemovableChip(text) {
    var chip = document.createElement('span');
    chip.className = 'chip';
    chip.appendChild(document.createTextNode(text));

    var removeBtn = document.createElement('button');
    removeBtn.type = 'button';
    removeBtn.className = 'chip-remove';
    removeBtn.setAttribute('aria-label', 'Remove ' + text);
    removeBtn.innerHTML = removeIconSvg();
    chip.appendChild(document.createTextNode(' '));
    chip.appendChild(removeBtn);

    return chip;
  }

  function wireSelectableChip(chip) {
    chip.addEventListener('click', function () {
      chip.classList.toggle('is-selected');
      chip.setAttribute('aria-pressed', chip.classList.contains('is-selected') ? 'true' : 'false');
      var field = chip.closest('.ai-field');
      if (field) field.dataset.reviewed = 'true';
    });
  }

  function createSelectableChip(text, className) {
    var chip = document.createElement('button');
    chip.type = 'button';
    chip.className = 'chip ' + className + ' is-selected';
    chip.setAttribute('aria-pressed', 'true');
    chip.innerHTML = checkIconSvg();
    chip.appendChild(document.createTextNode(' ' + text));
    wireSelectableChip(chip);
    return chip;
  }

  function renderRemovableChips(fieldName, source, emptyMessage) {
    var container = document.querySelector('[data-ai-field="' + fieldName + '"]');
    if (!container) return;
    container.innerHTML = '';

    var items = Array.isArray(source) ? source : splitClinicalList(source);
    if (!items.length) {
      renderEmpty(container, emptyMessage);
      markAiGenerated(container);
      return;
    }

    items.forEach(function (item) {
      container.appendChild(createRemovableChip(item));
    });
    markAiGenerated(container);
  }

  function renderIcd10(codes) {
    var container = document.querySelector('[data-ai-field="icd10"]');
    if (!container) return;
    container.innerHTML = '';

    if (!Array.isArray(codes) || !codes.length) {
      renderEmpty(container, 'No ICD-10 code returned.');
      markAiGenerated(container);
      return;
    }

    codes.forEach(function (entry) {
      var label = [entry.code, entry.description].filter(Boolean).join(' - ');
      if (label) container.appendChild(createSelectableChip(label, 'chip-selectable'));
    });
    if (!container.children.length) renderEmpty(container, 'No ICD-10 code returned.');
    markAiGenerated(container);
  }

  function renderInvestigations(source) {
    var container = document.querySelector('[data-ai-field="investigations"]');
    if (!container) return;
    container.innerHTML = '';

    var items = Array.isArray(source) ? source : splitClinicalList(source);
    if (!items.length) {
      renderEmpty(container, 'No investigations documented.');
      markAiGenerated(container);
      return;
    }

    items.forEach(function (item) {
      container.appendChild(createSelectableChip(item, 'chip-checkbox'));
    });
    markAiGenerated(container);
  }

  function createAdviceItem(text) {
    var li = document.createElement('li');
    li.className = 'advice-item';

    var dot = document.createElement('span');
    dot.className = 'w-1.5 h-1.5 rounded-full bg-[var(--primary)] shrink-0';

    var body = document.createElement('span');
    body.className = 'flex-1';
    body.textContent = text;

    var removeBtn = document.createElement('button');
    removeBtn.type = 'button';
    removeBtn.className = 'chip-remove';
    removeBtn.setAttribute('aria-label', 'Remove advice');
    removeBtn.innerHTML = removeIconSvg().replace('w-3 h-3', 'w-3.5 h-3.5');

    li.appendChild(dot);
    li.appendChild(body);
    li.appendChild(removeBtn);
    return li;
  }

  function createRxRow(values) {
    values = values || {};
    var remarks = [values.route ? 'Route: ' + values.route : '', values.remarks || '']
      .filter(Boolean)
      .join('; ');
    var columns = [
      ['Medicine', values.medicine || ''],
      ['Dose', values.dose || ''],
      ['Frequency', values.frequency || ''],
      ['Duration', values.duration || ''],
      ['Remarks', remarks],
    ];

    var tr = document.createElement('tr');
    columns.forEach(function (column) {
      var td = document.createElement('td');
      var input = document.createElement('input');
      input.type = 'text';
      input.className = 'rx-input';
      input.placeholder = column[0];
      input.setAttribute('aria-label', column[0]);
      input.value = column[1];
      td.appendChild(input);
      tr.appendChild(td);
    });

    var actionTd = document.createElement('td');
    actionTd.innerHTML = '<button type="button" class="rx-row-remove" aria-label="Remove row"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M5 7h14"/><path d="M9 7V5.5A1.5 1.5 0 0 1 10.5 4h3A1.5 1.5 0 0 1 15 5.5V7"/><path d="M7 7l1 12.5A2 2 0 0 0 10 21.5h4a2 2 0 0 0 2-1.5L17 7"/><path d="M10 11v6M14 11v6"/></svg></button>';
    tr.appendChild(actionTd);

    return tr;
  }

  function renderMedicationRows(medications) {
    var tableBody = document.getElementById('rx-table-body');
    if (!tableBody) return;

    tableBody.innerHTML = '';
    if (!Array.isArray(medications) || !medications.length) {
      markAiGenerated(tableBody);
      return;
    }

    medications.forEach(function (medication) {
      tableBody.appendChild(createRxRow(medication));
    });
    markAiGenerated(tableBody);
  }

  function renderAdvice(source) {
    var list = document.querySelector('[data-ai-field="advice"]');
    if (!list) return;
    list.innerHTML = '';

    var items = Array.isArray(source) ? source : splitClinicalList(source);
    items.forEach(function (item) {
      list.appendChild(createAdviceItem(item));
    });
    markAiGenerated(list);
  }

  function clearStructuredMockFields() {
    document.querySelectorAll('.vital-value').forEach(function (field) {
      field.value = '';
      markAiGenerated(field);
    });

    var systemicExam = document.querySelector('textarea[aria-label="Systemic Examination"]');
    if (systemicExam) {
      systemicExam.value = '';
      systemicExam.dispatchEvent(new Event('input', { bubbles: true }));
      markAiGenerated(systemicExam);
    }

    var tableBody = document.getElementById('rx-table-body');
    if (tableBody) {
      tableBody.innerHTML = '';
      markAiGenerated(tableBody);
    }

    setTextField('follow_up_date', '');
  }

  function renderSuggestions(data) {
    if (!suggestionsList) return;
    suggestionsList.innerHTML = '';

    Object.keys(SECTION_TITLES).forEach(function (key) {
      var section = data[key];
      var suggestions = section && Array.isArray(section.suggestions) ? section.suggestions : [];
      if (!suggestions.length) return;

      var details = document.createElement('details');
      details.className = 'suggestion-group';
      details.open = true;

      var summary = document.createElement('summary');
      summary.innerHTML = '<span class="flex items-center gap-2 text-[13px] font-medium">' +
        checkIconSvg() + escapeHtml(SECTION_TITLES[key]) + '</span>' + chevronIconSvg();

      var ul = document.createElement('ul');
      ul.className = 'mt-2 space-y-1.5 pl-1';
      suggestions.slice(0, 5).forEach(function (item) {
        var li = document.createElement('li');
        li.className = 'text-[13px] text-[var(--text-muted)] flex items-start gap-2';
        li.innerHTML = '<span class="w-1 h-1 rounded-full bg-[var(--text-muted)] mt-[7px] shrink-0"></span>' + escapeHtml(item);
        ul.appendChild(li);
      });

      details.appendChild(summary);
      details.appendChild(ul);
      suggestionsList.appendChild(details);
    });

    if (!suggestionsList.children.length) {
      renderEmpty(suggestionsList, 'No documentation suggestions returned.');
    }
  }

  function renderConfidenceScores(data) {
    var container = document.getElementById('confidence-scores-list');
    if (!container || !data) return;
    container.innerHTML = '';

    Object.keys(SECTION_TITLES).forEach(function (key) {
      var section = data[key] || {};
      var raw = typeof section.confidence === 'number' ? section.confidence : 0;
      var pct = Math.round(raw * 100);
      var level = confidenceLevel(raw);

      var row = document.createElement('div');

      var head = document.createElement('div');
      head.className = 'flex items-center justify-between text-xs mb-1.5';

      var label = document.createElement('span');
      label.className = 'font-medium flex items-center gap-1.5';
      if (level === 'low') label.innerHTML = alertIconSvg('w-3.5 h-3.5 text-[var(--accent-amber)]');
      label.appendChild(document.createTextNode(SECTION_TITLES[key]));

      var pctEl = document.createElement('span');
      pctEl.className = 'confidence-pct confidence-' + level;
      pctEl.textContent = pct + '%';

      head.appendChild(label);
      head.appendChild(pctEl);

      var track = document.createElement('div');
      track.className = 'confidence-track';
      var fill = document.createElement('div');
      fill.className = 'confidence-fill confidence-' + level;
      fill.style.setProperty('--val', pct + '%');
      track.appendChild(fill);

      row.appendChild(head);
      row.appendChild(track);
      container.appendChild(row);
    });
  }

  function renderDrugInteractions(interactions) {
    var container = document.getElementById('drug-interaction-alert');
    if (!container) return;
    container.innerHTML = '';

    if (!Array.isArray(interactions) || !interactions.length) {
      var ok = document.createElement('div');
      ok.className = 'alert-box alert-box-ok';
      ok.innerHTML = checkIconSvg().replace('w-3.5 h-3.5', 'w-4 h-4') + '<p>No drug interactions detected.</p>';
      container.appendChild(ok);
      return;
    }

    interactions.forEach(function (interaction) {
      var box = document.createElement('div');
      box.className = 'alert-box';
      var severity = interaction.severity ? ' (' + escapeHtml(interaction.severity) + ')' : '';
      var description = interaction.description ? ' — ' + escapeHtml(interaction.description) : '';
      box.innerHTML = alertIconSvg() +
        '<p><strong>Drug Interaction' + severity + ':</strong> ' +
        escapeHtml(interaction.drug_a || '') + ' + ' + escapeHtml(interaction.drug_b || '') +
        description + '</p>';
      container.appendChild(box);
    });
  }

  function applyClinicalExtraction(data) {
    clearStructuredMockFields();
    var examination = data.clinical_examination || {};
    var prescription = data.prescription || {};
    var followUp = data.follow_up || {};

    setTextField('chief_complaint', sectionDisplayText(data.chief_complaint));
    setTextField('history_of_present_illness', sectionDisplayText(data.history_of_present_illness));
    renderRemovableChips('past_history', sectionItems(data.past_history), 'No past history documented.');
    setTextField('family_history', sectionDisplayText(data.family_history));
    setTextField('drug_allergy', sectionDisplayText(data.drug_allergy));
    setTextField(
      'clinical_examination',
      examination.general_examination || sectionDisplayText(examination)
    );

    var systemicExam = document.querySelector('textarea[aria-label="Systemic Examination"]');
    if (systemicExam) {
      systemicExam.value = examination.systemic_examination || '';
      systemicExam.dispatchEvent(new Event('input', { bubbles: true }));
      markAiGenerated(systemicExam);
    }
    renderVitals(examination.vitals);

    setTextField('diagnosis', sectionDisplayText(data.diagnosis));
    renderIcd10(data.diagnosis && data.diagnosis.icd10);
    renderInvestigations(sectionItems(data.investigations));
    setTextField('prescription', sectionDisplayText(prescription));
    renderMedicationRows(prescription.medications);
    renderAdvice(sectionItems(data.advice));
    setTextField(
      'follow_up',
      [followUp.date_text || '', followUp.instruction || sectionDisplayText(followUp)]
        .filter(Boolean)
        .join('\n')
    );
  }

  function setGenerateUi(isLoading, message) {
    if (generateLabel) generateLabel.textContent = isLoading ? 'Generating...' : 'Generate AI Consultation';
    if (generateStatus) generateStatus.textContent = message || 'Consultation is generated automatically from your recording or uploaded audio.';
  }

  function animateConfidenceBars() {
    document.querySelectorAll('.confidence-fill').forEach(function (el) {
      el.classList.remove('is-animated');
      void el.offsetWidth;
      el.classList.add('is-animated');
    });
  }

  // "Generate AI Consultation" is no longer a standalone action — extraction
  // is driven entirely by the live/streamed audio (mic or upload). The
  // button/status line are kept as a shared status display for both flows.
  if (generateBtn) generateBtn.disabled = true;
  setGenerateUi(false);

  /* ---------------- Autosave simulation ---------------- */
  var autosaveText = document.getElementById('autosave-text');
  var autosaveDirty = false;
  document.addEventListener('input', function () { autosaveDirty = true; });

  setInterval(function () {
    if (!autosaveDirty) return;
    autosaveText.textContent = 'Saving…';
    setTimeout(function () {
      autosaveText.textContent = 'All changes saved';
      autosaveDirty = false;
    }, 700);
  }, 10000);

  /* ---------------- Chip add/remove: Past Medical History ---------------- */
  function wireChipList(listId, inputId, addBtnId) {
    var list = document.getElementById(listId);
    var input = document.getElementById(inputId);
    var addBtn = document.getElementById(addBtnId);
    if (!list || !input || !addBtn) return;

    function addChip() {
      var val = input.value.trim();
      if (!val) return;
      var chip = document.createElement('span');
      chip.className = 'chip';
      chip.innerHTML = val.replace(/[<>&]/g, function (c) {
        return { '<': '&lt;', '>': '&gt;', '&': '&amp;' }[c];
      }) + ' <button type="button" class="chip-remove" aria-label="Remove ' + val + '">' +
        '<svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M6 6l12 12M18 6 6 18"/></svg></button>';
      list.appendChild(chip);
      input.value = '';
      input.focus();
      list.closest('.ai-field').dataset.reviewed = 'true';
    }

    addBtn.addEventListener('click', addChip);
    input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') { e.preventDefault(); addChip(); }
    });
    list.addEventListener('click', function (e) {
      var btn = e.target.closest('.chip-remove');
      if (btn) btn.closest('.chip').remove();
    });
  }
  wireChipList('pmh-chips', 'pmh-input', 'pmh-add');

  /* ---------------- Advice list add/remove ---------------- */
  (function wireAdvice() {
    var list = document.getElementById('advice-list');
    var input = document.getElementById('advice-input');
    var addBtn = document.getElementById('advice-add');
    if (!list || !input || !addBtn) return;

    function addItem() {
      var val = input.value.trim();
      if (!val) return;
      var li = document.createElement('li');
      li.className = 'advice-item';
      var safe = val.replace(/[<>&]/g, function (c) { return { '<': '&lt;', '>': '&gt;', '&': '&amp;' }[c]; });
      li.innerHTML = '<span class="w-1.5 h-1.5 rounded-full bg-[var(--primary)] shrink-0"></span>' +
        '<span class="flex-1">' + safe + '</span>' +
        '<button type="button" class="chip-remove" aria-label="Remove advice"><svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M6 6l12 12M18 6 6 18"/></svg></button>';
      list.appendChild(li);
      input.value = '';
      input.focus();
      list.closest('.ai-field').dataset.reviewed = 'true';
    }

    addBtn.addEventListener('click', addItem);
    input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); addItem(); } });
    list.addEventListener('click', function (e) {
      var btn = e.target.closest('.chip-remove');
      if (btn) btn.closest('.advice-item').remove();
    });
  })();

  /* ---------------- Toggle chips: ICD-10 / Investigations ---------------- */
  document.querySelectorAll('.chip-selectable, .chip-checkbox').forEach(wireSelectableChip);

  /* ---------------- Prescription table: add / remove row ---------------- */
  var rxBody = document.getElementById('rx-table-body');
  document.getElementById('rx-add-row').addEventListener('click', function () {
    var tr = createRxRow();
    rxBody.appendChild(tr);
    tr.querySelector('input').focus();
    rxBody.closest('.ai-field').dataset.reviewed = 'true';
  });
  rxBody.addEventListener('click', function (e) {
    var btn = e.target.closest('.rx-row-remove');
    if (btn) btn.closest('tr').remove();
  });

  /* ---------------- Follow-up quick date select ---------------- */
  document.querySelectorAll('.quick-followup').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var days = parseInt(btn.dataset.days, 10);
      var d = new Date();
      d.setDate(d.getDate() + days);
      var iso = d.toISOString().slice(0, 10);
      var dateInput = btn.closest('article').querySelector('input[type="date"]');
      dateInput.value = iso;
      btn.closest('.ai-field').dataset.reviewed = 'true';
    });
  });

  /* ---------------- Confidence bars: animate in on load ---------------- */
  requestAnimationFrame(function () {
    setTimeout(animateConfidenceBars, 250);
  });

  /* ---------------- Voice recording state machine ---------------- */
  var micBtn = document.getElementById('mic-btn');
  var micPulse = document.getElementById('mic-pulse');
  var micPulse2 = document.getElementById('mic-pulse-2');
  var stateBadge = document.getElementById('rec-state-badge');
  var timerEl = document.getElementById('rec-timer');
  var captionEl = document.getElementById('rec-caption');
  var waveBars = document.querySelectorAll('.wave-bar');
  var btnPause = document.getElementById('btn-pause');
  var btnResume = document.getElementById('btn-resume');
  var btnStop = document.getElementById('btn-stop');
  var transcriptLive = document.getElementById('transcript-live');
  var transcriptBox = document.getElementById('transcript-box');
  var transcriptPlaceholder = document.getElementById('transcript-placeholder');

  var langSelect = document.getElementById('rec-lang');
  var unsupportedNotice = document.getElementById('asr-unsupported');

  var STATE = 'ready'; // ready | listening | paused | processing | completed
  var seconds = 0;
  var timerInterval = null;

  /* Live audio is streamed to the AI_backend WebSocket pipeline for STT + structuring. */
  var streamingSupported = !!(
    navigator.mediaDevices && navigator.mediaDevices.getUserMedia &&
    window.WebSocket &&
    (window.AudioContext || window.webkitAudioContext)
  );

  var micStream = null;
  var audioCtx = null;
  var audioMixer = null;
  var googleMeetStream = null;
  var googleMeetAudioTrack = null;
  var googleMeetButton = document.getElementById('btn-connect-google-meet');
  var googleMeetStatus = document.getElementById('google-meet-status');
  var wsSession = null;
  var capturing = false; // true while listening; false while paused (WS session stays open either way)

  if (!streamingSupported) {
    unsupportedNotice.classList.remove('hidden');
  }

  function setState(next) {
    STATE = next;
    var badgeMap = {
      ready: ['Ready', 'state-ready'],
      listening: ['Listening', 'state-listening'],
      paused: ['Paused', 'state-listening'],
      processing: ['Processing', 'state-processing'],
      completed: ['Completed', 'state-completed'],
    };
    var conf = badgeMap[next];
    stateBadge.className = 'state-badge ' + conf[1];
    stateBadge.innerHTML = '<span class="w-1.5 h-1.5 rounded-full bg-current"></span> ' + conf[0];

    var captionMap = {
      ready: consultationMode === 'google_meet'
        ? 'Connect Google Meet, then tap the mic to start'
        : 'Tap the mic to start the consultation',
      listening: 'Listening… speak naturally',
      paused: 'Recording paused',
      processing: 'AI is structuring the consultation…',
      completed: 'Consultation drafted — please review',
    };
    captionEl.textContent = captionMap[next];

    micBtn.classList.toggle('is-listening', next === 'listening');
    micPulse.classList.toggle('is-active', next === 'listening');
    micPulse2.classList.toggle('is-active', next === 'listening');
    waveBars.forEach(function (b) { b.classList.toggle('is-active', next === 'listening'); });

    btnPause.disabled = next !== 'listening';
    btnResume.disabled = next !== 'paused';
    btnStop.disabled = !(next === 'listening' || next === 'paused');

    micBtn.setAttribute('aria-label', next === 'listening' ? 'Pause or stop recording' : 'Start recording');
  }

  function tick() {
    seconds += 1;
    var m = Math.floor(seconds / 60).toString().padStart(2, '0');
    var s = (seconds % 60).toString().padStart(2, '0');
    timerEl.textContent = m + ':' + s;
  }

  function resetTranscriptLive() {
    transcriptLive.innerHTML = '<span class="transcript-cursor">▍</span>';
  }

  function addTranscriptLine(speaker, text) {
    text = String(text || '').trim();
    if (!text) return;
    transcriptPlaceholder.classList.add('hidden');
    var p = document.createElement('p');
    p.className = 'transcript-line dynamic-line';
    p.dataset.transcriptText = text;
    p.innerHTML = '<span class="transcript-speaker text-[var(--primary)]">' + escapeHtml(speaker) + ':</span> ' + escapeHtml(text);
    transcriptLive.parentNode.insertBefore(p, transcriptLive);
    transcriptBox.scrollTop = transcriptBox.scrollHeight;
  }

  function appendFinalLine(text) {
    addTranscriptLine('Doctor', text);
  }

  function renderTranscriptTurns(turns) {
    transcriptBox.querySelectorAll('.dynamic-line').forEach(function (el) { el.remove(); });
    if (!Array.isArray(turns) || !turns.length) {
      transcriptPlaceholder.classList.remove('hidden');
      return;
    }
    transcriptPlaceholder.classList.add('hidden');
    turns.forEach(function (turn) {
      addTranscriptLine((turn && turn.speaker) || 'Speaker', turn && turn.text);
    });
  }

  function setInterimText(text) {
    transcriptLive.innerHTML = text
      ? '<span class="transcript-speaker text-[var(--primary)]">Doctor:</span> ' + escapeHtml(text) + '<span class="transcript-cursor">▍</span>'
      : '<span class="transcript-cursor">▍</span>';
    transcriptBox.scrollTop = transcriptBox.scrollHeight;
  }

  function updateGoogleMeetUi(isConnecting) {
    if (!googleMeetButton || !googleMeetStatus) return;
    var connected = !!googleMeetStream;
    var state = connected ? 'connected' : (isConnecting ? 'connecting' : 'disconnected');
    googleMeetButton.textContent = connected ? 'Disconnect Google Meet' : (isConnecting ? 'Choosing Google Meet tab...' : 'Connect Google Meet');
    googleMeetButton.dataset.state = state;
    googleMeetButton.disabled = !!isConnecting;
    googleMeetButton.setAttribute('aria-pressed', connected ? 'true' : 'false');
    googleMeetStatus.dataset.state = state;
    googleMeetStatus.innerHTML = connected
      ? 'Google Meet tab audio is connected. Start the recording to stream both voices.'
      : (isConnecting
        ? 'Choose the active Google Meet tab and enable <strong>Share tab audio</strong> in the browser prompt.'
        : 'Select the active Google Meet tab, then enable <strong>Share tab audio</strong> in the browser prompt.');
  }

  function releaseGoogleMeet() {
    if (!googleMeetStream) return;
    if (audioMixer) audioMixer.removeSource('google-meet');
    if (googleMeetAudioTrack) googleMeetAudioTrack.onended = null;
    googleMeetStream.getTracks().forEach(function (track) { track.stop(); });
    googleMeetStream = null;
    googleMeetAudioTrack = null;
    updateGoogleMeetUi();
  }

  function teardownAudioGraph() {
    if (audioMixer) {
      audioMixer.close();
      audioMixer = null;
    }
    if (micStream) {
      micStream.getTracks().forEach(function (t) { t.stop(); });
      micStream = null;
    }
    if (audioCtx) {
      audioCtx.close();
      audioCtx = null;
    }
  }

  async function connectGoogleMeet() {
    if (consultationMode !== 'google_meet') return;
    if (!navigator.mediaDevices || !navigator.mediaDevices.getDisplayMedia) {
      showToast('Google Meet tab audio sharing requires a current Chrome or Edge browser');
      return;
    }
    if (googleMeetStream) {
      releaseGoogleMeet();
      return;
    }
    if (STATE === 'listening' || STATE === 'processing') {
      showToast('Connect Google Meet before starting the recording');
      return;
    }

    try {
      updateGoogleMeetUi(true);
      // Browsers require video=true for tab selection. The application never
      // renders or transmits the video track; it uses only the selected tab's audio.
      var sharedStream = await navigator.mediaDevices.getDisplayMedia({
        video: true,
        audio: true,
        preferCurrentTab: false,
        selfBrowserSurface: 'exclude',
        systemAudio: 'include',
      });
      var audioTrack = sharedStream.getAudioTracks()[0];
      if (!audioTrack) {
        sharedStream.getTracks().forEach(function (track) { track.stop(); });
        updateGoogleMeetUi();
        showToast('No tab audio was shared. Select the Google Meet tab and enable Share tab audio.');
        return;
      }
      googleMeetStream = sharedStream;
      googleMeetAudioTrack = audioTrack;
      googleMeetAudioTrack.onended = function () {
        if (!googleMeetStream) return;
        if (audioMixer) audioMixer.removeSource('google-meet');
        googleMeetStream.getVideoTracks().forEach(function (track) { track.stop(); });
        googleMeetStream = null;
        googleMeetAudioTrack = null;
        updateGoogleMeetUi();
        showToast('Google Meet tab audio sharing stopped');
        if (STATE === 'listening') pauseRecording();
      };
      if (audioMixer) audioMixer.addSource('google-meet', googleMeetStream);
      updateGoogleMeetUi();
    } catch (e) {
      updateGoogleMeetUi();
      // A cancellation is normal when the doctor closes the browser picker.
      if (e && e.name !== 'AbortError') showToast('Could not connect Google Meet tab audio');
    }
  }

  function handleWsStatus(data) {
    if (generateStatus) generateStatus.textContent = data.message || '';
  }

  function handleWsInterim(data) {
    renderUsage(data.usage);
    if (data.transcript_turns) renderTranscriptTurns(data.transcript_turns);
    if (data.state) {
      applyClinicalExtraction(data.state);
      renderSuggestions(data.state);
      renderConfidenceScores(data.state);
    }
  }

  function handleWsFinal(data) {
    renderUsage(data.usage || (data.result && data.result._usage));
    if (data.result) {
      if (data.result.transcript_turns) renderTranscriptTurns(data.result.transcript_turns);
      applyClinicalExtraction(data.result);
      renderSuggestions(data.result);
      renderConfidenceScores(data.result);
      animateConfidenceBars();
      renderDrugInteractions(data.result.drug_interactions);
    }
    showPanel('consult');
    setGenerateUi(false, 'Consultation generated. Please review before saving.');
    showToast('AI consultation generated');
    setState('completed');
    // The final message proves STT + LLM processing has completed. Closing
    // here releases the client side only after that result has been received.
    if (wsSession) wsSession.close();
    wsSession = null;
  }

  function handleWsError(data) {
    showToast(data && data.message ? data.message : 'AI backend error');
    teardownAudioGraph();
    releaseGoogleMeet();
    capturing = false;
    wsSession = null;
    setState('ready');
  }

  function handleWsClose() {
    if (STATE === 'processing') {
      showToast('Connection to AI backend closed unexpectedly');
      setState('ready');
    }
    wsSession = null;
  }

  async function startRecording() {
    if (!streamingSupported) {
      showToast('Live voice transcription needs Chrome or Edge browser, with microphone access');
      return;
    }
    if (consultationMode === 'google_meet' && !googleMeetStream) {
      showToast('Connect Google Meet and enable Share tab audio before recording');
      return;
    }

    try {
      micStream = await navigator.mediaDevices.getUserMedia({
        audio: {
          channelCount: 1,
          sampleRate: wsSampleRate,
          echoCancellation: true,
          noiseSuppression: true,
        },
      });
    } catch (e) {
      showToast('Microphone access was denied — allow it in your browser to record');
      return;
    }

    var AudioContextImpl = window.AudioContext || window.webkitAudioContext;
    audioCtx = new AudioContextImpl({ sampleRate: wsSampleRate });
    audioMixer = HimsWsStream.createPCM16Mixer(audioCtx, function (int16) {
      if (!capturing || !wsSession) return;
      wsSession.sendPCM16(int16.buffer);
    });
    audioMixer.addSource('microphone', micStream);
    if (googleMeetStream) audioMixer.addSource('google-meet', googleMeetStream);
    if (audioCtx.state === 'suspended') await audioCtx.resume();

    wsSession = HimsWsStream.createSession(aiWsUrl, {
      onStatus: handleWsStatus,
      onInterim: handleWsInterim,
      onFinal: handleWsFinal,
      onError: handleWsError,
      onClose: handleWsClose,
    });

    capturing = true;
    setState('listening');
    timerInterval = setInterval(tick, 1000);
    transcriptBox.querySelectorAll('.dynamic-line').forEach(function (el) { el.remove(); });
    transcriptPlaceholder.classList.add('hidden');
    resetTranscriptLive();
    langSelect.disabled = true;
  }

  function stopRecording() {
    clearInterval(timerInterval);
    capturing = false;
    langSelect.disabled = false;
    teardownAudioGraph();
    releaseGoogleMeet();
    if (wsSession) wsSession.end();
    transcriptLive.innerHTML = '';
    if (!transcriptBox.querySelector('.dynamic-line')) {
      transcriptPlaceholder.classList.remove('hidden');
    }
    setState('processing');
    setGenerateUi(true, 'Generating consultation from your recording...');
  }

  function pauseRecording() {
    clearInterval(timerInterval);
    capturing = false;
    setState('paused');
  }

  function resumeRecording() {
    capturing = true;
    setState('listening');
    timerInterval = setInterval(tick, 1000);
  }

  micBtn.addEventListener('click', function () {
    if (STATE === 'ready' || STATE === 'completed') {
      if (STATE === 'completed') { seconds = 0; timerEl.textContent = '00:00'; }
      startRecording();
    } else if (STATE === 'listening') {
      stopRecording();
    } else if (STATE === 'paused') {
      resumeRecording();
    }
  });

  btnPause.addEventListener('click', pauseRecording);
  btnResume.addEventListener('click', resumeRecording);

  btnStop.addEventListener('click', function () {
    if (STATE === 'listening' || STATE === 'paused') stopRecording();
  });

  updateGoogleMeetUi();
  if (googleMeetButton) googleMeetButton.addEventListener('click', connectGoogleMeet);
  window.addEventListener('beforeunload', function () {
    teardownAudioGraph();
    releaseGoogleMeet();
  });

  /* ---------------- Upload audio file (real: pick/drag-drop, validate, preview) ---------------- */
  (function wireAudioUpload() {
    var zone = document.getElementById('upload-zone');
    var input = document.getElementById('audio-file-input');
    var card = document.getElementById('uploaded-file-card');
    var nameEl = document.getElementById('uploaded-file-name');
    var metaEl = document.getElementById('uploaded-file-meta');
    var audioEl = document.getElementById('uploaded-file-audio');
    var removeBtn = document.getElementById('uploaded-file-remove');
    var zoneLabel = document.getElementById('upload-zone-label');
    var transcribeBtn = document.getElementById('btn-transcribe-upload');
    var transcribeLabel = document.getElementById('btn-transcribe-label');
    var currentObjectUrl = null;
    var currentFile = null;
    var MAX_SIZE = 50 * 1024 * 1024; // 50MB

    function formatSize(bytes) {
      if (bytes < 1024) return bytes + ' B';
      if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
      return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
    }

    function acceptFile(file) {
      if (!file) return;
      if (!file.type.startsWith('audio/')) {
        showToast('Please choose an audio file (MP3, WAV, M4A, OGG…)');
        return;
      }
      if (file.size > MAX_SIZE) {
        showToast('File is too large — please keep it under 50MB');
        return;
      }
      if (currentObjectUrl) URL.revokeObjectURL(currentObjectUrl);
      currentObjectUrl = URL.createObjectURL(file);
      currentFile = file;
      audioEl.src = currentObjectUrl;
      nameEl.textContent = file.name;
      metaEl.textContent = formatSize(file.size) + ' · ' + (file.type || 'audio file');
      card.classList.remove('hidden');
      zoneLabel.textContent = 'Replace audio file';
      transcribeBtn.disabled = false;
      transcribeLabel.textContent = 'Transcribe this recording';
      showToast('Audio file attached');
    }

    function streamUploadToBackend(pcm16) {
      return new Promise(function (resolve, reject) {
        var FRAME_SAMPLES = wsSampleRate; // ~1s per frame
        var BACKPRESSURE_BYTES = 1024 * 1024;
        // source=upload: the backend routes uploaded (already-recorded) audio through
        // batch REST STT instead of the real-time streaming API, since this file is
        // sent far faster than real time and the streaming API can't keep up with that.
        var uploadWsUrl = aiWsUrl + (aiWsUrl.indexOf('?') === -1 ? '?' : '&') + 'source=upload';

        var session = HimsWsStream.createSession(uploadWsUrl, {
          onStatus: function (d) { transcribeLabel.textContent = d.message || 'Transcribing…'; },
          onInterim: function (d) {
            renderUsage(d.usage);
            if (d.transcript_turns) renderTranscriptTurns(d.transcript_turns);
          },
          onFinal: function (d) {
            renderUsage(d.usage || (d.result && d.result._usage));
            if (d.result) {
              if (d.result.transcript_turns) renderTranscriptTurns(d.result.transcript_turns);
              applyClinicalExtraction(d.result);
              renderSuggestions(d.result);
              renderConfidenceScores(d.result);
              animateConfidenceBars();
              renderDrugInteractions(d.result.drug_interactions);
            }
            showPanel('consult');
            resolve();
          },
          onError: function (d) { reject(new Error(d && d.message ? d.message : 'Transcription failed')); },
        });

        function sendNextFrame(offset) {
          if (offset >= pcm16.length) {
            session.end();
            return;
          }
          if (session.bufferedAmount() > BACKPRESSURE_BYTES) {
            setTimeout(function () { sendNextFrame(offset); }, 20);
            return;
          }
          var slice = pcm16.subarray(offset, offset + FRAME_SAMPLES);
          session.sendPCM16(slice.buffer.slice(slice.byteOffset, slice.byteOffset + slice.byteLength));
          setTimeout(function () { sendNextFrame(offset + FRAME_SAMPLES); }, 0);
        }
        sendNextFrame(0);
      });
    }

    function transcribeUpload() {
      if (!currentFile) return;
      transcribeBtn.disabled = true;
      transcribeLabel.textContent = 'Transcribing…';

      currentFile.arrayBuffer()
        .then(function (buf) { return HimsWsStream.decodeFileToPCM16(buf, wsSampleRate); })
        .then(streamUploadToBackend)
        .then(function () {
          showToast('Transcription complete');
        })
        .catch(function (err) {
          showToast(err && err.message ? err.message : 'Could not reach the transcription server');
        })
        .finally(function () {
          transcribeBtn.disabled = false;
          transcribeLabel.textContent = 'Transcribe this recording';
        });
    }

    transcribeBtn.addEventListener('click', transcribeUpload);

    zone.addEventListener('click', function () { input.click(); });
    zone.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); input.click(); }
    });
    input.addEventListener('change', function () {
      acceptFile(input.files[0]);
    });

    ['dragenter', 'dragover'].forEach(function (evt) {
      zone.addEventListener(evt, function (e) {
        e.preventDefault();
        zone.classList.add('is-dragover');
      });
    });
    ['dragleave', 'drop'].forEach(function (evt) {
      zone.addEventListener(evt, function (e) {
        e.preventDefault();
        zone.classList.remove('is-dragover');
      });
    });
    zone.addEventListener('drop', function (e) {
      var file = e.dataTransfer.files && e.dataTransfer.files[0];
      acceptFile(file);
    });

    removeBtn.addEventListener('click', function (e) {
      e.stopPropagation();
      if (currentObjectUrl) URL.revokeObjectURL(currentObjectUrl);
      currentObjectUrl = null;
      currentFile = null;
      audioEl.removeAttribute('src');
      audioEl.load();
      input.value = '';
      card.classList.add('hidden');
      zoneLabel.textContent = 'Upload Audio (optional)';
      transcribeBtn.disabled = false;
      transcribeLabel.textContent = 'Transcribe this recording';
    });
  })();

  /* ---------------- Save Draft / Save Consultation / History restore ---------------- */

  function fieldValue(name) {
    var el = document.querySelector('[data-ai-field="' + name + '"]');
    return el ? el.value.trim() : '';
  }

  function elementTexts(selector) {
    return Array.prototype.slice.call(document.querySelectorAll(selector))
      .map(function (el) { return el.textContent.trim(); })
      .filter(Boolean);
  }

  function collectVitals() {
    return Array.prototype.slice.call(document.querySelectorAll('[data-vital-card]'))
      .map(function (card) {
        var input = card.querySelector('.vital-value');
        return {
          label: card.getAttribute('data-vital-card') || '',
          value: input ? input.value.trim() : '',
          unit: '',
        };
      })
      .filter(function (v) { return v.value; });
  }

  function collectMedications() {
    return Array.prototype.slice.call(document.querySelectorAll('#rx-table-body tr'))
      .map(function (tr) {
        var inputs = tr.querySelectorAll('input');
        return {
          medicine: inputs[0] ? inputs[0].value.trim() : '',
          dose: inputs[1] ? inputs[1].value.trim() : '',
          route: '',
          frequency: inputs[2] ? inputs[2].value.trim() : '',
          duration: inputs[3] ? inputs[3].value.trim() : '',
          remarks: inputs[4] ? inputs[4].value.trim() : '',
        };
      })
      .filter(function (m) { return m.medicine || m.dose || m.frequency || m.duration || m.remarks; });
  }

  function collectConsultationForm() {
    var systemicExamEl = document.querySelector('textarea[aria-label="Systemic Examination"]');
    var followUpDateEl = document.querySelector('[data-ai-field="follow_up_date"]');

    return {
      chief_complaint: { text: fieldValue('chief_complaint'), items: [], suggestions: [] },
      history_of_present_illness: { text: fieldValue('history_of_present_illness'), items: [], suggestions: [] },
      past_history: { text: '', items: elementTexts('#pmh-chips .chip'), suggestions: [] },
      family_history: { text: fieldValue('family_history'), items: [], suggestions: [] },
      drug_allergy: { text: fieldValue('drug_allergy'), items: [], suggestions: [] },
      clinical_examination: {
        text: '',
        items: [],
        vitals: collectVitals(),
        general_examination: fieldValue('clinical_examination'),
        systemic_examination: systemicExamEl ? systemicExamEl.value.trim() : '',
        suggestions: [],
      },
      diagnosis: {
        text: fieldValue('diagnosis'),
        items: [],
        icd10: elementTexts('[data-ai-field="icd10"] .chip-selectable.is-selected').map(function (t) {
          return { code: t, description: '' };
        }),
        suggestions: [],
      },
      investigations: { text: '', items: elementTexts('[data-ai-field="investigations"] .chip-checkbox.is-selected'), suggestions: [] },
      prescription: {
        text: fieldValue('prescription'),
        items: [],
        medications: collectMedications(),
        suggestions: [],
      },
      advice: { text: '', items: elementTexts('#advice-list .advice-item .flex-1'), suggestions: [] },
      follow_up: { text: '', items: [], date_text: '', instruction: fieldValue('follow_up'), suggestions: [] },
      follow_up_date_iso: followUpDateEl ? followUpDateEl.value : '',
      _usage: lastUsage,
    };
  }

  function restoreSavedConsultation(data) {
    if (!data) return;
    applyClinicalExtraction(data);
    var followUpDateEl = document.querySelector('[data-ai-field="follow_up_date"]');
    if (followUpDateEl && data.follow_up_date_iso) followUpDateEl.value = data.follow_up_date_iso;
  }

  function saveConsultation(status) {
    return fetch('/api/consultation/save.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'csrf_token=' + encodeURIComponent(csrfToken) +
        '&status=' + encodeURIComponent(status) +
        '&data=' + encodeURIComponent(JSON.stringify(collectConsultationForm())),
    })
      .then(function (res) { return res.json().catch(function () { return {}; }); })
      .then(function (json) { return !!(json && json.success); })
      .catch(function () { return false; });
  }

  var savedConsultationRaw = document.body.dataset.savedConsultation;
  if (savedConsultationRaw) {
    try { restoreSavedConsultation(JSON.parse(savedConsultationRaw)); } catch (e) { /* ignore malformed saved data */ }
  }

  var btnSaveDraft = document.getElementById('btn-save-draft');
  var btnSaveConsultation = document.getElementById('btn-save-consultation');
  var btnPrint = document.getElementById('btn-print');
  var btnGeneratePdf = document.getElementById('btn-generate-pdf');
  var btnEmail = document.getElementById('btn-email');
  var btnSms = document.getElementById('btn-sms');

  if (btnSaveDraft) {
    btnSaveDraft.addEventListener('click', function () {
      saveConsultation('draft').then(function (ok) {
        showToast(ok ? 'Draft saved' : 'Could not save draft');
      });
    });
  }

  if (btnSaveConsultation) {
    btnSaveConsultation.addEventListener('click', function () {
      if (!window.confirm('Finalize and save this consultation?')) return;
      saveConsultation('final').then(function (ok) {
        showToast(ok ? 'Consultation saved' : 'Could not save consultation');
      });
    });
  }

  function openPrintView() {
    saveConsultation('draft').then(function () {
      window.open('/consultation/print.php?visit_id=' + encodeURIComponent(visitId) + '&autoprint=1', '_blank');
    });
  }

  if (btnPrint) btnPrint.addEventListener('click', openPrintView);
  if (btnGeneratePdf) btnGeneratePdf.addEventListener('click', openPrintView);

  function buildShareSummary() {
    var form = collectConsultationForm();
    var lines = [];
    lines.push('Consultation Summary');
    if (form.diagnosis.text) lines.push('Diagnosis: ' + form.diagnosis.text);
    if (form.prescription.medications.length) {
      lines.push('Prescription:');
      form.prescription.medications.forEach(function (m) {
        lines.push('- ' + [m.medicine, m.dose, m.frequency, m.duration].filter(Boolean).join(' '));
      });
    }
    if (form.advice.items.length) lines.push('Advice: ' + form.advice.items.join('; '));
    if (form.follow_up.instruction) lines.push('Follow-up: ' + form.follow_up.instruction);
    return lines.join('\n');
  }

  if (btnEmail) {
    var btnEmailLabel = btnEmail.querySelector('span');
    btnEmail.addEventListener('click', function () {
      var email = window.prompt('Enter the patient\'s email address:');
      if (!email) return;
      email = email.trim();
      if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
        showToast('Please enter a valid email address');
        return;
      }

      btnEmail.disabled = true;
      if (btnEmailLabel) btnEmailLabel.textContent = 'Sending…';

      saveConsultation('draft')
        .then(function () {
          return fetch('/api/consultation/send-email.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'csrf_token=' + encodeURIComponent(csrfToken) + '&email=' + encodeURIComponent(email),
          });
        })
        .then(function (res) { return res.json().catch(function () { return {}; }); })
        .then(function (data) {
          showToast(data.success ? 'Email sent to ' + email : (data.error || 'Could not send email'));
        })
        .catch(function () {
          showToast('Could not reach the server to send email');
        })
        .finally(function () {
          btnEmail.disabled = false;
          if (btnEmailLabel) btnEmailLabel.textContent = 'Send Email';
        });
    });
  }

  if (btnSms) {
    btnSms.addEventListener('click', function () {
      var number = window.prompt('Enter phone number to send SMS to:');
      if (!number) return;
      var text = buildShareSummary();
      window.location.href = 'sms:' + encodeURIComponent(number) + '?body=' + encodeURIComponent(text);
    });
  }

  /* ---------------- Voice commands panel: make each command actually run ---------------- */
  var voiceCommandSectionIndex = -1;

  var voiceCommandHandlers = {
    start_recording: function () {
      if (STATE === 'ready' || STATE === 'completed') {
        if (STATE === 'completed') { seconds = 0; timerEl.textContent = '00:00'; }
        startRecording();
      } else {
        showToast('Already recording');
      }
    },
    stop_recording: function () {
      if (STATE === 'listening' || STATE === 'paused') {
        stopRecording();
      } else {
        showToast('Not currently recording');
      }
    },
    next_section: function () {
      var sections = document.querySelectorAll('#main-content .ai-field');
      if (!sections.length) { showToast('No sections to jump to'); return; }
      voiceCommandSectionIndex = (voiceCommandSectionIndex + 1) % sections.length;
      showPanel('consult');
      sections[voiceCommandSectionIndex].scrollIntoView({ behavior: 'smooth', block: 'center' });
    },
    print_prescription: function () {
      openPrintView();
    },
    save_consultation: function () {
      if (!window.confirm('Finalize and save this consultation?')) return;
      saveConsultation('final').then(function (ok) {
        showToast(ok ? 'Consultation saved' : 'Could not save consultation');
      });
    },
    open_previous_visit: function () {
      var link = document.querySelector('#previous-visits-list a');
      if (link) {
        window.open(link.href, '_blank');
      } else {
        showToast('No previous visits for this patient');
      }
    },
    next_patient: function () {
      window.location.href = '/patients/select.php';
    },
  };

  document.querySelectorAll('.voice-command-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var handler = voiceCommandHandlers[btn.dataset.command];
      if (handler) handler();
    });
  });

  setState('ready');
})();
