<?php
require __DIR__ . '/../../includes/core/icons.php';
require __DIR__ . '/../../includes/core/auth.php';
require __DIR__ . '/../../includes/core/db.php';

if (!empty($_SESSION['doctor_id'])) {
    header('Location: /intake/dashboard.php');
    exit;
}

$errors = [];
$full_name = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    $password_confirm = (string) ($_POST['password_confirm'] ?? '');

    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Invalid form submission. Please try again.';
    }
    if ($full_name === '') {
        $errors[] = 'Please enter your full name.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }
    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    }
    if ($password !== $password_confirm) {
        $errors[] = 'Passwords do not match.';
    }

    if (!$errors) {
        $stmt = $pdo->prepare('SELECT id FROM doctors WHERE email = ?');
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $errors[] = 'An account with this email already exists.';
        }
    }

    if (!$errors) {
        // The very first account on a fresh install becomes the clinic admin —
        // there's no other bootstrap path to get an admin, since only an admin
        // can promote a doctor (see /api/admin/save-doctor-role.php).
        $isFirstAccount = (int) $pdo->query('SELECT COUNT(*) FROM doctors')->fetchColumn() === 0;
        $role = $isFirstAccount ? 'admin' : 'doctor';

        $stmt = $pdo->prepare('INSERT INTO doctors (full_name, email, password_hash, role) VALUES (?, ?, ?, ?)');
        $stmt->execute([$full_name, $email, password_hash($password, PASSWORD_DEFAULT), $role]);

        session_regenerate_id(true);
        $_SESSION['doctor_id'] = (int) $pdo->lastInsertId();
        unset($_SESSION['patient_id'], $_SESSION['visit_id']);
        header('Location: /intake/dashboard.php');
        exit;
    }
}

$isFirstAccount = (int) $pdo->query('SELECT COUNT(*) FROM doctors')->fetchColumn() === 0;
$page_title = 'Create Account — HIMS Voice AI';
require __DIR__ . '/../../includes/layout/auth-shell-head.php';
?>

<div class="card p-8 w-full max-w-md">
  <div class="flex items-center gap-2.5 mb-6">
    <div class="w-9 h-9 rounded-xl bg-[var(--primary)] text-white flex items-center justify-center shadow-card">
      <?= icon('sparkles', 'w-5 h-5') ?>
    </div>
    <div class="leading-tight">
      <p class="font-bold text-[15px] tracking-tight">HIMS <span class="text-[var(--primary)]">Voice AI</span></p>
      <p class="text-[11px] text-[var(--text-muted)] -mt-0.5">Doctor Registration</p>
    </div>
  </div>

  <?php if ($isFirstAccount): ?>
    <div class="alert-box mb-4">
      <?= icon('alert', 'w-4 h-4 shrink-0') ?>
      <p>This is the first account on this install — it will become the clinic <strong>admin</strong> (telephony setup, agent script &amp; guardrails).</p>
    </div>
  <?php endif; ?>

  <?php if ($errors): ?>
    <div class="alert-box mb-4">
      <?= icon('alert', 'w-4 h-4 shrink-0') ?>
      <div>
        <?php foreach ($errors as $err): ?>
          <p><?= htmlspecialchars($err) ?></p>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <form method="post" class="space-y-4">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">

    <div>
      <label for="full_name" class="text-[11px] uppercase tracking-wide text-[var(--text-muted)] mb-1 block">Full Name</label>
      <input type="text" id="full_name" name="full_name" required autofocus class="field-input" value="<?= htmlspecialchars($full_name) ?>" placeholder="Shreya Mehta">
    </div>

    <div>
      <label for="email" class="text-[11px] uppercase tracking-wide text-[var(--text-muted)] mb-1 block">Email</label>
      <input type="email" id="email" name="email" required class="field-input" value="<?= htmlspecialchars($email) ?>" placeholder="doctor@example.com">
    </div>

    <div>
      <label for="password" class="text-[11px] uppercase tracking-wide text-[var(--text-muted)] mb-1 block">Password</label>
      <input type="password" id="password" name="password" required class="field-input" placeholder="At least 8 characters">
    </div>

    <div>
      <label for="password_confirm" class="text-[11px] uppercase tracking-wide text-[var(--text-muted)] mb-1 block">Confirm Password</label>
      <input type="password" id="password_confirm" name="password_confirm" required class="field-input" placeholder="••••••••">
    </div>

    <button type="submit" class="w-full py-2.5 rounded-xl bg-gradient-to-b from-[var(--primary)] to-[var(--primary-dark)] text-white font-medium text-sm cursor-pointer hover:opacity-95 transition-opacity">
      Create Account
    </button>
  </form>

  <p class="text-[12px] text-[var(--text-muted)] mt-5 text-center">
    Already have an account? <a href="/auth/login.php" class="text-[var(--primary)] font-medium">Sign in</a>
  </p>
</div>

<?php require __DIR__ . '/../../includes/layout/auth-shell-foot.php'; ?>
