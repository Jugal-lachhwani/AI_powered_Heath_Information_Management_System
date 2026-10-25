(function () {
  'use strict';

  var csrfToken = window.__telephonyAdminCsrf;

  function postForm(url, formData) {
    formData.set('csrf_token', csrfToken);
    return fetch(url, { method: 'POST', body: formData })
      .then(function (res) { return res.json().catch(function () { return { success: false }; }); });
  }

  function showToast(message) {
    var toast = document.createElement('div');
    toast.textContent = message;
    toast.style.cssText = 'position:fixed;bottom:24px;left:50%;transform:translateX(-50%);' +
      'background:var(--text);color:var(--bg);padding:.6rem 1.1rem;border-radius:.6rem;font-size:12.5px;z-index:100;';
    document.body.appendChild(toast);
    setTimeout(function () { toast.remove(); }, 2500);
  }

  var addNumberForm = document.getElementById('add-number-form');
  if (addNumberForm) {
    addNumberForm.addEventListener('submit', function (e) {
      e.preventDefault();
      postForm('/api/admin/save-telephony-number.php', new FormData(addNumberForm)).then(function (res) {
        if (res.success) window.location.reload();
        else showToast(res.error || 'Could not add number');
      });
    });
  }

  document.querySelectorAll('[data-delete-number]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      if (!window.confirm('Remove this number?')) return;
      var fd = new FormData();
      fd.set('action', 'delete');
      fd.set('id', btn.dataset.deleteNumber);
      postForm('/api/admin/save-telephony-number.php', fd).then(function (res) {
        if (res.success) window.location.reload();
      });
    });
  });

  ['calling-window-form', 'retention-form', 'sms-sender-form'].forEach(function (id) {
    var form = document.getElementById(id);
    if (!form) return;
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var fd = new FormData(form);
      // Unchecked checkboxes aren't included in FormData — make that explicit for mask_numbers.
      var maskInput = form.querySelector('[name="mask_numbers"]');
      if (maskInput && !maskInput.checked) fd.delete('mask_numbers');
      postForm('/api/admin/save-telephony-settings.php', fd).then(function (res) {
        showToast(res.success ? 'Saved' : (res.error || 'Could not save'));
      });
    });
  });

  document.querySelectorAll('[data-toggle-role]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var newRole = btn.dataset.newRole;
      var verb = newRole === 'admin' ? 'Make this doctor an admin?' : 'Remove admin access from this doctor?';
      if (!window.confirm(verb)) return;
      var fd = new FormData();
      fd.set('doctor_id', btn.dataset.toggleRole);
      fd.set('role', newRole);
      postForm('/api/admin/save-doctor-role.php', fd).then(function (res) {
        if (res.success) window.location.reload();
        else showToast(res.error || 'Could not update role');
      });
    });
  });
})();
