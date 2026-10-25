<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>AI Voice Consultation — HIMS</title>
<meta name="description" content="AI-powered voice consultation module for HIMS: speech-to-text clinical documentation for doctors.">

<script>
  // Set theme before paint to avoid flash-of-wrong-theme
  (function () {
    var saved = localStorage.getItem('hims-theme');
    var theme = saved || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
    document.documentElement.setAttribute('data-theme', theme);
  })();
</script>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Figtree:wght@400;500;600;700;800&family=Noto+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">

<script src="https://cdn.tailwindcss.com"></script>
<script>
  tailwind.config = {
    darkMode: ['selector', '[data-theme="dark"]'],
    theme: {
      extend: {
        fontFamily: {
          sans: ['Figtree', 'Noto Sans', 'ui-sans-serif', 'system-ui', 'sans-serif'],
        },
        boxShadow: {
          card: '0 1px 2px rgba(15,42,40,.04), 0 8px 24px -12px rgba(15,42,40,.12)',
        },
      },
    },
  };
</script>

<link rel="stylesheet" href="/assets/css/style.css">
</head>
<body data-ai-ws-url="<?= htmlspecialchars($ai_ws_url, ENT_QUOTES) ?>" data-ai-ws-sample-rate="<?= htmlspecialchars($ai_ws_sample_rate, ENT_QUOTES) ?>" data-consultation-mode="<?= htmlspecialchars($consultation_mode, ENT_QUOTES) ?>" data-visit-id="<?= (int) $_SESSION['visit_id'] ?>" data-csrf-token="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>" data-saved-consultation="<?= htmlspecialchars($saved_consultation_data ?? '', ENT_QUOTES) ?>" class="consultation-shell min-h-screen flex flex-col lg:h-screen lg:overflow-hidden bg-[var(--bg)] text-[var(--text)] font-sans antialiased transition-colors duration-300">

<a href="#main-content" class="skip-link">Skip to main content</a>

<!-- ============ TOP HEADER : PATIENT CONTEXT BAR ============ -->
<header class="consultation-header sticky top-0 z-40 shrink-0 border-b border-[var(--border)] bg-[var(--surface)]/90 backdrop-blur-md">
  <div class="mx-auto max-w-none px-4 sm:px-6 lg:px-8">
    <div class="flex items-center gap-4 h-16">

      <!-- Brand -->
      <a href="/intake/dashboard.php" class="clinical-brand flex items-center gap-2.5 shrink-0">
        <div class="clinical-brand-mark w-9 h-9 rounded-xl text-white flex items-center justify-center shadow-card">
          <?= icon('hospital', 'w-5 h-5') ?>
        </div>
        <div class="hidden sm:block leading-tight">
          <p class="font-bold text-[15px] tracking-tight">HIMS <span class="text-[var(--primary)]">Voice AI</span></p>
          <p class="text-[11px] text-[var(--text-muted)] -mt-0.5">Doctor Console</p>
        </div>
      </a>

      <div class="hidden lg:block w-px h-8 bg-[var(--border)] shrink-0"></div>

      <!-- Patient info -->
      <div class="flex-1 min-w-0 overflow-x-auto no-scrollbar">
        <div class="flex items-center gap-5 lg:gap-7 whitespace-nowrap py-1">
          <div class="clinical-patient-chip flex items-center gap-2">
            <span class="patient-avatar w-8 h-8 rounded-full bg-[var(--surface-2)] text-[var(--primary)] flex items-center justify-center shrink-0">
              <?= icon('user', 'w-4.5 h-4.5') ?>
            </span>
            <div class="leading-tight">
              <p class="font-semibold text-sm"><?= htmlspecialchars($patient['name']) ?></p>
              <p class="text-[11px] text-[var(--text-muted)]"><?= (int)$patient['age'] ?> yrs · <?= htmlspecialchars($patient['gender']) ?></p>
            </div>
          </div>
          <div class="clinical-meta-group hidden md:block leading-tight">
            <p class="text-[10px] uppercase tracking-wide text-[var(--text-muted)]">UHID</p>
            <p class="text-sm font-medium"><?= htmlspecialchars($patient['uhid']) ?></p>
          </div>
          <div class="clinical-meta-group hidden md:block leading-tight">
            <p class="text-[10px] uppercase tracking-wide text-[var(--text-muted)]">Visit No.</p>
            <p class="text-sm font-medium"><?= htmlspecialchars($patient['visit_no']) ?></p>
          </div>
          <div class="clinical-meta-group hidden xl:block leading-tight">
            <p class="text-[10px] uppercase tracking-wide text-[var(--text-muted)]">Doctor</p>
            <p class="text-sm font-medium"><?= htmlspecialchars($patient['doctor']) ?></p>
          </div>
          <div class="clinical-meta-group hidden xl:block leading-tight">
            <p class="text-[10px] uppercase tracking-wide text-[var(--text-muted)]">Date &amp; Time</p>
            <p class="text-sm font-medium"><?= htmlspecialchars($patient['datetime']) ?></p>
          </div>
        </div>
      </div>

      <!-- Actions -->
      <div class="flex items-center gap-2 shrink-0">
        <a href="/intake/dashboard.php" class="hidden md:inline-flex items-center text-[12px] font-medium text-[var(--text-muted)] hover:text-[var(--text)] px-2.5 py-1 rounded-full bg-[var(--surface-2)] hover:bg-[var(--border)] transition-colors">
          &larr; AI Intake Calls
        </a>

        <span id="autosave-indicator" class="hidden sm:inline-flex items-center gap-1.5 text-[11px] text-[var(--text-muted)] px-2.5 py-1 rounded-full bg-[var(--surface-2)]">
          <span class="w-1.5 h-1.5 rounded-full bg-[var(--accent-green)]"></span>
          <span id="autosave-text">All changes saved</span>
        </span>

        <button id="theme-toggle" type="button" aria-label="Toggle dark mode" title="Toggle dark / light mode"
          class="w-10 h-10 rounded-xl border border-[var(--border)] bg-[var(--surface-2)] hover:bg-[var(--border)] transition-colors duration-200 flex items-center justify-center cursor-pointer focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--primary)]">
          <span class="theme-icon-light"><?= icon('sun', 'w-5 h-5') ?></span>
          <span class="theme-icon-dark hidden"><?= icon('moon', 'w-5 h-5') ?></span>
        </button>

        <a href="/auth/logout.php" title="Log out" aria-label="Log out"
          class="w-10 h-10 rounded-xl border border-[var(--border)] bg-[var(--surface-2)] hover:bg-[var(--border)] transition-colors duration-200 flex items-center justify-center cursor-pointer">
          <?= icon('x', 'w-5 h-5') ?>
        </a>

        <button type="button" aria-label="Open panel menu" id="mobile-panel-menu-btn"
          class="lg:hidden w-10 h-10 rounded-xl border border-[var(--border)] bg-[var(--surface-2)] hover:bg-[var(--border)] transition-colors duration-200 flex items-center justify-center cursor-pointer">
          <?= icon('menu', 'w-5 h-5') ?>
        </button>
      </div>
    </div>
  </div>

  <!-- Mobile panel switch (Record / Consultation / AI Assistant) -->
  <div class="lg:hidden border-t border-[var(--border)] bg-[var(--surface)]">
    <div class="mx-auto max-w-none px-4 sm:px-6">
      <div class="flex items-center gap-1 py-2" role="tablist" aria-label="Consultation panels">
        <button data-panel-tab="record" class="panel-tab is-active" role="tab" aria-selected="true">Record</button>
        <button data-panel-tab="consult" class="panel-tab" role="tab" aria-selected="false">Consultation</button>
        <button data-panel-tab="assistant" class="panel-tab" role="tab" aria-selected="false">AI Assistant</button>
      </div>
    </div>
  </div>
</header>

<main id="main-content" class="mx-auto max-w-none w-full px-4 sm:px-6 lg:px-8 py-5 sm:py-6 flex-1 lg:min-h-0 lg:overflow-hidden lg:flex lg:flex-col">
  <div class="consultation-workspace grid grid-cols-1 gap-5 lg:gap-6 items-start lg:items-stretch lg:flex-1 lg:min-h-0">
