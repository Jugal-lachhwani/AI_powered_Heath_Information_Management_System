<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($page_title ?? 'HIMS Voice AI') ?></title>

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
<?php foreach ($extra_stylesheets ?? [] as $href): ?>
<link rel="stylesheet" href="<?= htmlspecialchars($href) ?>">
<?php endforeach; ?>
</head>
<body<?php foreach ($body_data_attrs ?? [] as $attr => $value): ?> <?= $attr ?>="<?= htmlspecialchars((string) $value, ENT_QUOTES) ?>"<?php endforeach; ?> class="app-shell min-h-screen flex flex-col bg-[var(--bg)] text-[var(--text)] font-sans antialiased transition-colors duration-300">

<a href="#main-content" class="skip-link">Skip to main content</a>

<!-- ============ TOP BAR : brand + doctor + logout + primary nav ============ -->
<header class="sticky top-0 z-40 shrink-0 border-b border-[var(--border)] bg-[var(--surface)]/90 backdrop-blur-md">
  <div class="mx-auto max-w-none px-4 sm:px-6 lg:px-8">
    <div class="flex items-center gap-4 h-16">
      <a href="/intake/dashboard.php" class="flex items-center gap-2.5 shrink-0">
        <div class="w-9 h-9 rounded-xl bg-[var(--primary)] text-white flex items-center justify-center shadow-card">
          <?= icon('sparkles', 'w-5 h-5') ?>
        </div>
        <div class="leading-tight">
          <p class="font-bold text-[15px] tracking-tight">HIMS <span class="text-[var(--primary)]">Voice AI</span></p>
          <p class="text-[11px] text-[var(--text-muted)] -mt-0.5"><?= htmlspecialchars($shell_subtitle ?? 'AI Intake Calls') ?></p>
        </div>
      </a>

      <div class="flex-1"></div>

      <div class="flex items-center gap-2 shrink-0">
        <?php if (!empty($doctor)): ?>
          <span class="hidden sm:inline-flex text-[12px] text-[var(--text-muted)] px-2.5">
            Dr. <?= htmlspecialchars($doctor['full_name']) ?>
          </span>
        <?php endif; ?>

        <button id="theme-toggle" type="button" aria-label="Toggle dark mode" title="Toggle dark / light mode"
          class="w-10 h-10 rounded-xl border border-[var(--border)] bg-[var(--surface-2)] hover:bg-[var(--border)] transition-colors duration-200 flex items-center justify-center cursor-pointer focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--primary)]">
          <span class="theme-icon-light"><?= icon('sun', 'w-5 h-5') ?></span>
          <span class="theme-icon-dark hidden"><?= icon('moon', 'w-5 h-5') ?></span>
        </button>

        <a href="/auth/logout.php" title="Log out" aria-label="Log out"
          class="w-10 h-10 rounded-xl border border-[var(--border)] bg-[var(--surface-2)] hover:bg-[var(--border)] transition-colors duration-200 flex items-center justify-center cursor-pointer">
          <?= icon('x', 'w-5 h-5') ?>
        </a>
      </div>
    </div>

    <!-- Primary nav — one consistent bar across the whole site -->
    <?php
      $navItems = [
        ['href' => '/intake/dashboard.php', 'label' => 'Dashboard'],
        ['href' => '/intake/calls.php', 'label' => 'Calls'],
        ['href' => '/intake/queue.php', 'label' => 'Queue'],
        ['href' => '/intake/analytics.php', 'label' => 'Analytics'],
      ];
      if (($doctor['role'] ?? 'doctor') === 'admin') {
        $navItems[] = ['href' => '/admin/agent-config.php', 'label' => 'Agent config'];
        $navItems[] = ['href' => '/admin/telephony.php', 'label' => 'Telephony & access'];
      }
      $navItems[] = ['href' => '/patients/select.php', 'label' => 'Voice consultation'];
      $currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    ?>
    <nav aria-label="Primary" class="flex items-center gap-1 overflow-x-auto no-scrollbar pb-2 -mt-0.5">
      <?php foreach ($navItems as $item): ?>
        <a href="<?= htmlspecialchars($item['href']) ?>"
          class="shrink-0 px-3 py-1.5 rounded-lg text-[12.5px] font-medium whitespace-nowrap transition-colors <?= $currentPath === $item['href'] ? 'bg-[var(--primary)] text-white' : 'text-[var(--text-muted)] hover:bg-[var(--surface-2)]' ?>">
          <?= htmlspecialchars($item['label']) ?>
        </a>
      <?php endforeach; ?>
    </nav>
  </div>
</header>

<main id="main-content" class="mx-auto max-w-none w-full px-4 sm:px-6 lg:px-8 py-5 sm:py-6 flex-1">
