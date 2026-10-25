(function () {
  'use strict';

  var renderConfidenceRow = window.HimsClinicalFormat.renderConfidenceRow;

  var body = document.body;
  var callId = body.dataset.callId;
  var csrfToken = body.dataset.csrfToken;
  var visitId = parseInt(body.dataset.visitId, 10) || 0;
  var reviewStatus = body.dataset.reviewStatus;

  var data = window.__intakeReviewData || { state: {}, tasks: [], transcript: [], redFlags: [], sectionTitles: {} };

  function postForm(url, fields) {
    var params = new URLSearchParams();
    params.set('csrf_token', csrfToken);
    Object.keys(fields).forEach(function (key) { params.set(key, fields[key]); });
    return fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: params.toString(),
    }).then(function (res) { return res.json().catch(function () { return { success: false }; }); });
  }

  function showToast(message) {
    var toast = document.createElement('div');
    toast.textContent = message;
    toast.style.cssText = 'position:fixed;bottom:88px;left:50%;transform:translateX(-50%);' +
      'background:var(--text);color:var(--bg);padding:.6rem 1.1rem;border-radius:.6rem;font-size:12.5px;z-index:100;';
    document.body.appendChild(toast);
    setTimeout(function () { toast.remove(); }, 2500);
  }

  /* ---------------- Confidence overview ---------------- */

  var confidenceOverview = document.getElementById('confidence-overview');
  if (confidenceOverview) {
    ['chief_complaint', 'history_of_present_illness', 'past_history', 'family_history', 'current_medication', 'drug_allergy']
      .forEach(function (key) {
        var section = data.state[key] || {};
        renderConfidenceRow(confidenceOverview, data.sectionTitles[key] || key, section.confidence || 0);
      });
  }

  /* ---------------- Transcript scrubber ---------------- */

  document.querySelectorAll('.scrubber-marker').forEach(function (marker) {
    marker.addEventListener('click', function () {
      var line = document.getElementById('t-line-' + marker.dataset.jump);
      if (line) line.scrollIntoView({ behavior: 'smooth', block: 'center' });
    });
  });

  /* ---------------- Confirm low-confidence field ---------------- */

  document.querySelectorAll('.confirm-field-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      btn.textContent = 'Confirmed ✓';
      btn.disabled = true;
      btn.classList.add('opacity-60');
    });
  });

  /* ---------------- Collect edited state from the DOM ---------------- */

  function collectState() {
    var state = JSON.parse(JSON.stringify(data.state));
    document.querySelectorAll('[data-intake-field]').forEach(function (el) {
      var key = el.dataset.intakeField;
      if (!state[key]) state[key] = { text: '', items: [], suggestions: [], confidence: 0 };
      state[key].text = el.value;
    });
    return state;
  }

  /* ---------------- Tasks checklist (persisted) ---------------- */

  document.querySelectorAll('[data-task-index]').forEach(function (checkbox) {
    checkbox.addEventListener('change', function () {
      var idx = parseInt(checkbox.dataset.taskIndex, 10);
      if (data.tasks[idx]) data.tasks[idx].done = checkbox.checked;
      checkbox.closest('.task-item').classList.toggle('is-done', checkbox.checked);
      saveDraft(true);
    });
  });

  /* ---------------- Action bar ---------------- */

  function saveDraft(silent) {
    data.state = collectState();
    return postForm('/api/intake/save-call.php', {
      call_id: callId,
      status: 'completed',
      current_step: 'wrapup',
      human_taken_over: '',
      transcript: JSON.stringify(data.transcript),
      state: JSON.stringify(data.state),
      red_flags: JSON.stringify(data.redFlags),
      tasks: JSON.stringify(data.tasks),
    }).then(function (res) {
      if (!silent) showToast(res.success ? 'Draft saved' : 'Could not save draft');
      return res;
    });
  }

  var btnSaveDraft = document.getElementById('btn-save-draft');
  if (btnSaveDraft) btnSaveDraft.addEventListener('click', function () { saveDraft(false); });

  var btnApprove = document.getElementById('btn-approve');
  if (btnApprove) {
    btnApprove.addEventListener('click', function () {
      btnApprove.disabled = true;
      btnApprove.textContent = 'Approving…';
      saveDraft(true)
        .then(function () {
          return postForm('/api/intake/approve-call.php', { call_id: callId });
        })
        .then(function (res) {
          if (res.success) {
            visitId = res.visit_id;
            var badge = document.getElementById('review-status-badge');
            badge.className = 'state-badge state-completed';
            badge.textContent = 'Approved';
            var printUrl = '/consultation/print.php?visit_id=' + encodeURIComponent(visitId) + '&autoprint=1';
            ['btn-print', 'btn-pdf'].forEach(function (id) {
              var a = document.getElementById(id);
              a.href = printUrl;
              a.classList.remove('pointer-events-none', 'opacity-40');
            });
            showToast('Approved — attached to visit');
          } else {
            showToast(res.error || 'Could not approve');
          }
        })
        .finally(function () {
          btnApprove.disabled = false;
          btnApprove.textContent = 'Approve & attach to visit';
        });
    });
  }

  var btnDiscard = document.getElementById('btn-discard');
  if (btnDiscard) {
    btnDiscard.addEventListener('click', function () {
      if (!window.confirm('Discard this AI intake draft? This cannot be undone.')) return;
      postForm('/api/intake/discard-call.php', { call_id: callId }).then(function (res) {
        if (res.success) {
          var badge = document.getElementById('review-status-badge');
          badge.className = 'state-badge state-ready';
          badge.textContent = 'Discarded';
          showToast('Draft discarded');
        } else {
          showToast(res.error || 'Could not discard');
        }
      });
    });
  }

  var btnSms = document.getElementById('btn-sms');
  if (btnSms) {
    btnSms.addEventListener('click', function () {
      var number = window.prompt('Enter phone number to send SMS to:');
      if (!number) return;
      var state = collectState();
      var lines = ['AI Intake Summary'];
      if (state.chief_complaint && state.chief_complaint.text) lines.push('Complaint: ' + state.chief_complaint.text);
      if (state.current_medication && state.current_medication.text) lines.push('Current medication: ' + state.current_medication.text);
      if (state.drug_allergy && state.drug_allergy.text) lines.push('Allergies: ' + state.drug_allergy.text);
      window.location.href = 'sms:' + encodeURIComponent(number) + '?body=' + encodeURIComponent(lines.join('\n'));
    });
  }
})();
