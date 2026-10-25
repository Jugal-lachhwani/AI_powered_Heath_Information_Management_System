(function () {
  'use strict';

  var csrfToken = window.__agentConfigCsrf;

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
    toast.style.cssText = 'position:fixed;bottom:24px;left:50%;transform:translateX(-50%);' +
      'background:var(--text);color:var(--bg);padding:.6rem 1.1rem;border-radius:.6rem;font-size:12.5px;z-index:100;';
    document.body.appendChild(toast);
    setTimeout(function () { toast.remove(); }, 2500);
  }

  /* ---------------- Tabs ---------------- */

  var tabButtons = document.querySelectorAll('.config-tab-btn');
  var tabPanels = document.querySelectorAll('[data-tab-panel]');

  function activateTab(tab) {
    tabButtons.forEach(function (btn) { btn.classList.toggle('is-active', btn.dataset.tab === tab); });
    tabPanels.forEach(function (panel) { panel.classList.toggle('hidden', panel.dataset.tabPanel !== tab); });
  }

  tabButtons.forEach(function (btn) {
    btn.addEventListener('click', function () { activateTab(btn.dataset.tab); });
  });

  var urlTab = new URLSearchParams(window.location.search).get('tab');
  if (urlTab) activateTab(urlTab);

  /* ---------------- Script steps ---------------- */

  document.querySelectorAll('[data-step-id]').forEach(function (card) {
    var stepId = card.dataset.stepId;

    function saveStep() {
      postForm('/api/admin/save-agent-script.php', {
        action: 'edit_step',
        step_id: stepId,
        title: card.querySelector('[data-field="title"]').value,
        prompt_text: card.querySelector('[data-field="prompt_text"]').value,
        fills_field: card.querySelector('[data-field="fills_field"]').value,
      }).then(function (res) {
        if (res.success) showToast('Step saved');
      });
    }

    card.querySelectorAll('[data-field]').forEach(function (el) {
      el.addEventListener('change', saveStep);
    });

    var removeBtn = card.querySelector('[data-remove]');
    if (removeBtn) {
      removeBtn.addEventListener('click', function () {
        if (!window.confirm('Remove this step?')) return;
        postForm('/api/admin/save-agent-script.php', { action: 'remove_step', step_id: stepId }).then(function (res) {
          if (res.success) card.remove();
          else showToast(res.error || 'Could not remove step');
        });
      });
    }

    card.querySelectorAll('[data-move]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        postForm('/api/admin/save-agent-script.php', { action: 'move_step', step_id: stepId, direction: btn.dataset.move })
          .then(function (res) {
            if (res.success) window.location.reload();
          });
      });
    });
  });

  var addStepBtn = document.getElementById('btn-add-step');
  if (addStepBtn) {
    addStepBtn.addEventListener('click', function () {
      postForm('/api/admin/save-agent-script.php', { action: 'add_step', title: 'New step', prompt_text: '', fills_field: '' })
        .then(function (res) {
          if (res.success) window.location.reload();
          else showToast(res.error || 'Could not add step');
        });
    });
  }

  /* ---------------- Publish ---------------- */

  var publishBtn = document.getElementById('btn-publish');
  if (publishBtn) {
    publishBtn.addEventListener('click', function () {
      if (!window.confirm('Publish this draft? It will immediately become the script every new dynamic call uses.')) return;
      postForm('/api/admin/publish-agent-script.php', {}).then(function (res) {
        if (res.success) {
          showToast('Published v' + res.version);
          window.location.reload();
        } else {
          showToast(res.error || 'Could not publish');
        }
      });
    });
  }

  /* ---------------- Voice settings ---------------- */

  var voiceForm = document.getElementById('voice-form');
  if (voiceForm) {
    voiceForm.addEventListener('submit', function (e) {
      e.preventDefault();
      var formData = new FormData(voiceForm);
      var fields = {};
      formData.forEach(function (value, key) { fields[key] = value; });
      fields.allow_interrupt = voiceForm.querySelector('[name="allow_interrupt"]').checked ? '1' : '';
      postForm('/api/admin/save-agent-settings.php', fields).then(function (res) {
        showToast(res.success ? 'Voice settings saved' : (res.error || 'Could not save'));
      });
    });
  }
})();
