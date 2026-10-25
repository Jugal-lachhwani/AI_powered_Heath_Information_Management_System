</main>

<script>
  (function () {
    'use strict';
    var root = document.documentElement;
    var themeBtn = document.getElementById('theme-toggle');
    if (!themeBtn) return;

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
  })();
</script>
<?php foreach ($extra_scripts ?? [] as $src): ?>
<script src="<?= htmlspecialchars($src) ?>"></script>
<?php endforeach; ?>
</body>
</html>
