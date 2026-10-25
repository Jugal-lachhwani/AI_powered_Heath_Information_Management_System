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
</head>
<body class="auth-shell min-h-screen flex items-center justify-center bg-[var(--bg)] text-[var(--text)] font-sans antialiased transition-colors duration-300 p-4">
