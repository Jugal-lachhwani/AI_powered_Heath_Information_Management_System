<?php
require __DIR__ . '/../../includes/core/icons.php';
require __DIR__ . '/../../includes/core/auth.php';
require __DIR__ . '/../../includes/core/db.php';

if (!empty($_SESSION['doctor_id'])) {
    header('Location: /intake/dashboard.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        $error = 'Invalid form submission. Please try again.';
    } else {
        $email = trim($_POST['email'] ?? '');
        $password = (string) ($_POST['password'] ?? '');

        $row = null;
        if ($email !== '' && $password !== '') {
            $stmt = $pdo->prepare('SELECT id, password_hash FROM doctors WHERE email = ?');
            $stmt->execute([$email]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        if (!$row || !password_verify($password, $row['password_hash'])) {
            $error = 'Invalid email or password.';
        } else {
            session_regenerate_id(true);
            $_SESSION['doctor_id'] = $row['id'];
            unset($_SESSION['patient_id'], $_SESSION['visit_id']);
            header('Location: /intake/dashboard.php');
            exit;
        }
    }
}

$page_title = 'Sign In — HIMS Voice AI';
require __DIR__ . '/../../includes/layout/auth-shell-head.php';
?>

<div class="card p-8 w-full max-w-md">
  <div class="flex items-center gap-2.5 mb-6">
    <div class="w-9 h-9 rounded-xl bg-[var(--primary)] text-white flex items-center justify-center shadow-card">
      <?= icon('sparkles', 'w-5 h-5') ?>
    </div>
    <div class="leading-tight">
      <p class="font-bold text-[15px] tracking-tight">HIMS <span class="text-[var(--primary)]">Voice AI</span></p>
      <p class="text-[11px] text-[var(--text-muted)] -mt-0.5">Doctor Sign In</p>
    </div>
  </div>

  <?php if ($error): ?>
    <div class="alert-box mb-4">
      <?= icon('alert', 'w-4 h-4 shrink-0') ?>
      <p><?= htmlspecialchars($error) ?></p>
    </div>
  <?php endif; ?>

  <form method="post" class="space-y-4">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">

    <div>
      <label for="email" class="text-[11px] uppercase tracking-wide text-[var(--text-muted)] mb-1 block">Email</label>
      <input type="email" id="email" name="email" required autofocus class="field-input" placeholder="doctor@example.com">
    </div>

    <div>
      <label for="password" class="text-[11px] uppercase tracking-wide text-[var(--text-muted)] mb-1 block">Password</label>
      <input type="password" id="password" name="password" required class="field-input" placeholder="••••••••">
    </div>

    <button type="submit" class="w-full py-2.5 rounded-xl bg-gradient-to-b from-[var(--primary)] to-[var(--primary-dark)] text-white font-medium text-sm cursor-pointer hover:opacity-95 transition-opacity">
      Sign In
    </button>
  </form>

  <p class="text-[12px] text-[var(--text-muted)] mt-5 text-center">
    New doctor? <a href="/auth/register.php" class="text-[var(--primary)] font-medium">Create an account</a>
  </p>
</div>

<?php require __DIR__ . '/../../includes/layout/auth-shell-foot.php'; ?>
