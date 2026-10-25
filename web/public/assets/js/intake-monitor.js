(function () {
  'use strict';

  var confidenceLevel = window.HimsClinicalFormat.confidenceLevel;
  var escapeHtml = window.HimsClinicalFormat.escapeHtml;
  var renderConfidenceRow = window.HimsClinicalFormat.renderConfidenceRow;

  var body = document.body;
  var callId = body.dataset.callId;
  var callMode = body.dataset.callMode === 'fixed' ? 'fixed' : 'dynamic';
  var initData = window.__intakeMonitorInit || { steps: [], scenario_text: '', patient_name: '' };
  var wsUrl = callMode === 'fixed'
    ? body.dataset.aiWsIntakeUrl + '?mode=fixed&script=' + encodeURIComponent(body.dataset.scriptKey || '')
    : body.dataset.aiWsIntakeUrl + '?mode=dynamic';
  var csrfToken = body.dataset.csrfToken;

  var SECTION_TITLES = {
    chief_complaint: 'Chief Complaint',
    history_of_present_illness: 'History of Present Illness',
    past_history: 'Past History',
    family_history: 'Family History',
    current_medication: 'Current Medication',
    drug_allergy: 'Allergies',
  };
  // Fixed mode's step set is frozen (mock_intake_scripts.py); dynamic mode's steps come
  // from the doctor-authored script (could be any keys/order) — the stepper follows
  // whichever list actually applies to this call, not a single hardcoded assumption.
  var STEP_ORDER = callMode === 'dynamic' && initData.steps.length
    ? initData.steps.map(function (s) { return s.step_key; })
    : ['identity', 'consent', 'complaint', 'history', 'red_flags', 'meds', 'wrapup'];

  var callStatusBadge = document.getElementById('call-status-badge');
  var timerValueEl = document.getElementById('call-timer-value');
  var timerLabelEl = document.getElementById('call-timer-label');
  var speakerIndicator = document.getElementById('speaker-indicator');
  var stepperEl = document.getElementById('script-stepper');
  var transcriptBox = document.getElementById('live-transcript');
  var transcriptPlaceholder = document.getElementById('transcript-placeholder');
  var transcriptLive = document.getElementById('transcript-live');
  var confidenceCards = document.getElementById('confidence-cards');
  var sectionsFilledCount = document.getElementById('sections-filled-count');
  var tasksList = document.getElementById('tasks-list');
  var tasksEmpty = document.getElementById('tasks-empty');
  var redflagBanner = document.getElementById('redflag-banner');
  var redflagBannerDetail = document.getElementById('redflag-banner-detail');
  var whisperInput = document.getElementById('whisper-input');
  var usageFinalBadge = document.getElementById('usage-final-badge');
  var usageApiCalls = document.getElementById('usage-api-calls');
  var usageLlmCalls = document.getElementById('usage-llm-calls');
  var usageSttCalls = document.getElementById('usage-stt-calls');
  var usageInputTokens = document.getElementById('usage-input-tokens');
  var usageOutputTokens = document.getElementById('usage-output-tokens');
  var usageTotalTokens = document.getElementById('usage-total-tokens');
  var usageLlmTime = document.getElementById('usage-llm-time');
  var usageNote = document.getElementById('usage-note');

  var seconds = 0;
  var timerInterval = null;
  var humanTakenOver = false;
  var lastState = {};
  var lastStep = 'identity';
  var lastRedFlags = [];
  var lastTasks = [];
  var lastTranscriptTurns = [];
  var lastUsage = null;

  function formatNumber(value) {
    return Math.round(Number(value) || 0).toLocaleString('en-IN');
  }

  function renderUsage(usage) {
    if (!usage) return;
    lastUsage = usage;
    usageApiCalls.textContent = formatNumber(usage.api_calls);
    usageLlmCalls.textContent = formatNumber(usage.llm_api_calls || usage.api_calls);
    usageSttCalls.textContent = formatNumber(usage.stt_api_calls);
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

  function stateWithUsage(state) {
    var saved = JSON.parse(JSON.stringify(state || {}));
    if (lastUsage) saved._usage = lastUsage;
    return saved;
  }

  function postForm(url, fields) {
    var body = new URLSearchParams();
    body.set('csrf_token', csrfToken);
    Object.keys(fields).forEach(function (key) { body.set(key, fields[key]); });
    return fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body.toString(),
    }).catch(function () { /* best-effort — never block the live UI on a save failure */ });
  }

  function logEvent(eventType, payload) {
    postForm('/api/intake/log-event.php', {
      call_id: callId,
      event_type: eventType,
      payload: JSON.stringify(payload || {}),
    });
  }

  function saveCallState(status) {
    postForm('/api/intake/save-call.php', {
      call_id: callId,
      status: status,
      current_step: lastStep,
      human_taken_over: humanTakenOver ? '1' : '',
      transcript: JSON.stringify(lastTranscriptTurns),
      state: JSON.stringify(stateWithUsage(lastState)),
      red_flags: JSON.stringify(lastRedFlags),
      tasks: JSON.stringify(lastTasks),
    });
  }

  function tick() {
    seconds += 1;
    var m = Math.floor(seconds / 60).toString().padStart(2, '0');
    var s = (seconds % 60).toString().padStart(2, '0');
    timerValueEl.textContent = m + ':' + s;
  }

  function setCallStatus(text, cls) {
    callStatusBadge.className = 'state-badge ' + cls;
    callStatusBadge.innerHTML = '<span class="w-1.5 h-1.5 rounded-full bg-current"></span> ' + escapeHtml(text);
  }

  function updateStepper(step) {
    var idx = STEP_ORDER.indexOf(step);
    stepperEl.querySelectorAll('.stepper-step').forEach(function (el) {
      var stepIdx = STEP_ORDER.indexOf(el.dataset.step);
      el.classList.remove('is-done', 'is-active', 'is-upcoming');
      if (idx === -1) { el.classList.add('is-upcoming'); return; }
      if (stepIdx < idx) el.classList.add('is-done');
      else if (stepIdx === idx) el.classList.add('is-active');
      else el.classList.add('is-upcoming');
    });
    timerLabelEl.textContent = step ? step.replace('_', ' ') : 'Connecting';
  }

  function setSpeaker(speaker) {
    speakerIndicator.classList.remove('is-agent', 'is-patient');
    if (speaker === 'Agent') speakerIndicator.classList.add('is-agent');
    else if (speaker === 'Patient') speakerIndicator.classList.add('is-patient');
  }

  var lastTranscriptLineEl = null;

  function addTranscriptLine(speaker, text, step, whisperAnnotation) {
    text = String(text || '').trim();
    if (!text) return;
    transcriptPlaceholder.classList.add('hidden');

    var p = document.createElement('p');
    p.className = 'transcript-line dynamic-line';
    var color = speaker === 'Agent' ? 'text-[var(--primary)]' : 'text-[var(--accent-green)]';
    p.innerHTML =
      '<span class="section-tag">' + escapeHtml(step || '') + '</span>' +
      '<span class="transcript-speaker ' + color + '">' + escapeHtml(speaker) + ':</span> ' + escapeHtml(text);

    if (whisperAnnotation) {
      var note = document.createElement('div');
      note.className = 'text-[11px] text-[var(--accent-amber)] mt-0.5';
      note.textContent = '(whisper: ' + whisperAnnotation + ')';
      p.appendChild(note);
    }

    transcriptLive.parentNode.insertBefore(p, transcriptLive);
    transcriptBox.scrollTop = transcriptBox.scrollHeight;
    lastTranscriptLineEl = p;
  }

  function flagLastTranscriptLine() {
    if (lastTranscriptLineEl) lastTranscriptLineEl.classList.add('is-redflag');
  }

  function renderConfidenceCards(state) {
    confidenceCards.innerHTML = '';
    var filled = 0;
    Object.keys(SECTION_TITLES).forEach(function (key) {
      var section = state[key] || {};
      var confidence = typeof section.confidence === 'number' ? section.confidence : 0;
      if (section.text || (section.items && section.items.length)) filled += 1;
      var row = renderConfidenceRow(confidenceCards, SECTION_TITLES[key], confidence);
      if (section.text) {
        var textEl = document.createElement('p');
        textEl.className = 'text-[12px] text-[var(--text-muted)] mt-1';
        textEl.textContent = section.text;
        row.appendChild(textEl);
      }
    });
    sectionsFilledCount.textContent = filled + ' of 6 sections';
  }

  function renderTasks(tasks) {
    tasksList.querySelectorAll('.task-item').forEach(function (el) { el.remove(); });
    if (!tasks || !tasks.length) {
      tasksEmpty.classList.remove('hidden');
      return;
    }
    tasksEmpty.classList.add('hidden');
    tasks.forEach(function (task) {
      var item = document.createElement('label');
      item.className = 'task-item' + (task.priority === 'high' ? ' !border-[var(--accent-red)]' : '');
      item.innerHTML =
        '<input type="checkbox" disabled>' +
        '<span>' + escapeHtml(task.text || '') +
        '<span class="task-source">' + escapeHtml(task.source === 'red_flag' ? 'auto-raised by red flag' : 'low-confidence follow-up') + '</span>' +
        '</span>';
      tasksList.appendChild(item);
    });
  }

  function showRedFlagBanner(flag) {
    redflagBannerDetail.textContent = (flag.type || '').replace('_', ' ') + ' — ' + (flag.evidence || '');
    redflagBanner.classList.remove('hidden');
    logEvent('red_flag_detected', flag);
  }

  /* ---------------- WS session ---------------- */

  var session = window.HimsWsIntakeClient.createIntakeSession(wsUrl, {
    onOpen: function () {
      setCallStatus('Connected', 'state-listening');
      if (callMode === 'dynamic') {
        session.initScript(initData.steps, initData.scenario_text, initData.patient_name);
      }
    },
    onCallStatus: function (data) {
      humanTakenOver = !!data.human_taken_over;
      if (data.status === 'held') {
        setCallStatus('On hold', 'state-processing');
      } else if (humanTakenOver) {
        setCallStatus('Human taken over', 'state-processing');
      } else if (data.status === 'in_progress' && !timerInterval) {
        setCallStatus('In progress', 'state-listening');
        timerInterval = setInterval(tick, 1000);
      }
    },
    onTurn: function (data) {
      renderUsage(data.usage);
      setSpeaker(data.speaker);
      updateStepper(data.step);
      lastStep = data.step || lastStep;
      addTranscriptLine(data.speaker, data.text, data.step, data.whisper_annotation);
      lastTranscriptTurns.push({ speaker: data.speaker, text: data.text, step: data.step });
    },
    onInterim: function (data) {
      lastState = data.state || {};
      lastStep = data.step || lastStep;
      lastRedFlags = data.red_flags || [];
      lastTasks = data.tasks || [];
      lastTranscriptTurns = data.transcript_turns || lastTranscriptTurns;
      renderUsage(data.usage);
      renderConfidenceCards(lastState);
      renderTasks(lastTasks);
      saveCallState('in_progress');
    },
    onRedFlag: function (data) {
      flagLastTranscriptLine();
      showRedFlagBanner(data.flag || {});
    },
    onFinal: function (data) {
      clearInterval(timerInterval);
      setCallStatus('Completed', 'state-completed');
      var result = data.result || {};
      renderUsage(data.usage || result._usage);
      lastState = result;
      lastStep = result.current_step || lastStep;
      lastRedFlags = result.red_flags || [];
      lastTasks = result.tasks || [];
      lastTranscriptTurns = result.transcript_turns || lastTranscriptTurns;
      humanTakenOver = !!result.human_taken_over;
      saveCallState('completed');
      setTimeout(function () {
        window.location.href = '/intake/call-review.php?call_id=' + encodeURIComponent(callId);
      }, 600);
    },
    onError: function (data) {
      setCallStatus('Error', 'state-processing');
      console.error('Intake call error:', data && data.message);
    },
    onClose: function () {
      clearInterval(timerInterval);
    },
  });

  /* ---------------- Intervene panel wiring ---------------- */

  document.querySelectorAll('[data-whisper-preset]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      whisperInput.value = btn.dataset.whisperPreset;
    });
  });

  document.getElementById('btn-send-whisper').addEventListener('click', function () {
    var text = whisperInput.value.trim();
    if (!text) return;
    session.whisper(text);
    logEvent('whisper', { text: text });
    whisperInput.value = '';
  });

  function bargeIn() {
    session.bargeIn();
    logEvent('barge_in', {});
    setCallStatus('Human taken over', 'state-processing');
    redflagBanner.classList.add('hidden');
  }

  document.getElementById('btn-barge-in').addEventListener('click', bargeIn);
  document.getElementById('btn-redflag-bargein').addEventListener('click', bargeIn);

  document.getElementById('btn-notify-doctor').addEventListener('click', function () {
    logEvent('notify_doctor', {});
    this.textContent = 'Notified';
    this.disabled = true;
  });

  var muted = false;
  document.getElementById('btn-mute').addEventListener('click', function () {
    muted = !muted;
    session[muted ? 'mute' : 'unmute']();
    logEvent(muted ? 'mute' : 'unmute', {});
    this.textContent = muted ? 'Unmute AI' : 'Mute AI';
  });

  var held = false;
  document.getElementById('btn-hold').addEventListener('click', function () {
    held = !held;
    session[held ? 'hold' : 'resume']();
    logEvent(held ? 'hold' : 'resume', {});
    this.textContent = held ? 'Resume' : 'Hold';
  });

  document.getElementById('btn-end-call').addEventListener('click', function () {
    session.end();
    logEvent('call_ended', {});
  });
})();
