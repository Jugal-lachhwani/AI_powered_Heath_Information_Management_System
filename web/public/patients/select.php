<?php
require __DIR__ . '/../../includes/core/icons.php';
require __DIR__ . '/../../includes/core/auth.php';
require_login();
require __DIR__ . '/../../includes/core/db.php';

$doctor = current_doctor($pdo);
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Invalid form submission. Please try again.';
    } elseif ($action === 'start_visit') {
        $patientId = (int) ($_POST['patient_id'] ?? 0);
        $stmt = $pdo->prepare('SELECT id FROM patients WHERE id = ?');
        $stmt->execute([$patientId]);
        if (!$stmt->fetch()) {
            $errors[] = 'That patient record could not be found.';
        } else {
            $consultationMode = $_POST['consultation_mode'] ?? 'direct';
            if (!in_array($consultationMode, ['direct', 'google_meet'], true)) {
                $consultationMode = 'direct';
            }
            $visitId = start_visit_for_patient($pdo, $patientId, (int) $_SESSION['doctor_id']);
            $_SESSION['patient_id'] = $patientId;
            $_SESSION['visit_id'] = $visitId;
            $_SESSION['consultation_mode'] = $consultationMode;
            header('Location: /consultation/workspace.php');
            exit;
        }
    } elseif ($action === 'register_patient') {
        $name = trim($_POST['name'] ?? '');
        $age = (int) ($_POST['age'] ?? 0);
        $gender = $_POST['gender'] ?? '';
        $allowedGenders = ['Male', 'Female', 'Other'];

        if ($name === '') {
            $errors[] = 'Please enter the patient\'s name.';
        }
        if ($age < 0 || $age > 150) {
            $errors[] = 'Please enter a valid age.';
        }
        if (!in_array($gender, $allowedGenders, true)) {
            $errors[] = 'Please select a gender.';
        }
        $consultationMode = $_POST['consultation_mode'] ?? 'direct';
        if (!in_array($consultationMode, ['direct', 'google_meet'], true)) {
            $consultationMode = 'direct';
        }

        if (!$errors) {
            $stmt = $pdo->prepare(
                "INSERT INTO patients (uhid, name, age, gender, created_by_doctor_id) VALUES ('', ?, ?, ?, ?)"
            );
            $stmt->execute([$name, $age, $gender, (int) $_SESSION['doctor_id']]);
            $patientId = (int) $pdo->lastInsertId();
            generate_uhid($pdo, $patientId);

            $visitId = start_visit_for_patient($pdo, $patientId, (int) $_SESSION['doctor_id']);
            $_SESSION['patient_id'] = $patientId;
            $_SESSION['visit_id'] = $visitId;
            $_SESSION['consultation_mode'] = $consultationMode;
            header('Location: /consultation/workspace.php');
            exit;
        }
    }
}

$q = trim($_GET['q'] ?? '');
$results = [];
if ($q !== '') {
    $stmt = $pdo->prepare('SELECT id, uhid, name, age, gender FROM patients WHERE uhid = ? OR name LIKE ? ORDER BY name');
    $stmt->execute([$q, '%' . $q . '%']);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$page_title = 'Select Patient — HIMS Voice AI';
require __DIR__ . '/../../includes/layout/auth-shell-head.php';
?>

<div class="w-full max-w-2xl space-y-5">

  <div class="flex items-center justify-between">
    <div class="flex items-center gap-2.5">
      <div class="w-9 h-9 rounded-xl bg-[var(--primary)] text-white flex items-center justify-center shadow-card">
        <?= icon('sparkles', 'w-5 h-5') ?>
      </div>
      <div class="leading-tight">
        <p class="font-bold text-[15px] tracking-tight">HIMS <span class="text-[var(--primary)]">Voice AI</span></p>
        <p class="text-[11px] text-[var(--text-muted)] -mt-0.5">
          <?= $doctor ? 'Dr. ' . htmlspecialchars($doctor['full_name']) : '' ?> — Start a Consultation
        </p>
      </div>
    </div>
    <div class="flex items-center gap-3">
      <a href="/intake/dashboard.php" class="text-[12px] text-[var(--primary)] font-medium">&larr; Back to dashboard</a>
      <a href="/auth/logout.php" class="text-[12px] text-[var(--text-muted)] hover:text-[var(--text)]">Log out</a>
    </div>
  </div>

  <?php if ($errors): ?>
    <div class="alert-box">
      <?= icon('alert', 'w-4 h-4 shrink-0') ?>
      <div>
        <?php foreach ($errors as $err): ?>
          <p><?= htmlspecialchars($err) ?></p>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <!-- Find existing patient -->
  <div class="card p-6">
    <h2 class="font-semibold text-sm mb-3">Find Existing Patient</h2>
    <form method="get" class="flex gap-2 mb-4">
      <input type="text" name="q" class="field-input flex-1" value="<?= htmlspecialchars($q) ?>" placeholder="Search by UHID or name…">
      <button type="submit" class="px-4 rounded-xl bg-[var(--surface-2)] border border-[var(--border)] text-sm cursor-pointer hover:bg-[var(--border)]">Search</button>
    </form>

    <?php if ($q !== ''): ?>
      <?php if (!$results): ?>
        <p class="text-[13px] text-[var(--text-muted)]">No patient found for "<?= htmlspecialchars($q) ?>".</p>
      <?php else: ?>
        <div class="space-y-2">
          <?php foreach ($results as $p): ?>
            <div class="flex items-center justify-between gap-3 rounded-xl border border-[var(--border)] p-3">
              <div class="leading-tight">
                <p class="text-sm font-medium"><?= htmlspecialchars($p['name']) ?></p>
                <p class="text-[11px] text-[var(--text-muted)]"><?= (int) $p['age'] ?> yrs · <?= htmlspecialchars($p['gender']) ?> · <?= htmlspecialchars($p['uhid']) ?></p>
              </div>
              <form method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                <input type="hidden" name="action" value="start_visit">
                <input type="hidden" name="patient_id" value="<?= (int) $p['id'] ?>">
                <div class="flex gap-2">
                  <button type="submit" name="consultation_mode" value="direct" class="px-3.5 py-2 rounded-xl bg-gradient-to-b from-[var(--primary)] to-[var(--primary-dark)] text-white text-xs font-medium cursor-pointer hover:opacity-95">
                    Direct Consultation
                  </button>
                  <button type="submit" name="consultation_mode" value="google_meet" class="px-3.5 py-2 rounded-xl border border-[var(--primary)] text-[var(--primary)] text-xs font-medium cursor-pointer hover:bg-[var(--primary)]/10">
                    Online Consultation
                  </button>
                </div>
              </form>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <!-- Register new patient -->
  <div class="card p-6">
    <h2 class="font-semibold text-sm mb-3">Register New Patient</h2>
    <form method="post" class="space-y-4">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
      <input type="hidden" name="action" value="register_patient">

      <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
        <div class="sm:col-span-1">
          <label for="name" class="text-[11px] uppercase tracking-wide text-[var(--text-muted)] mb-1 block">Name</label>
          <input type="text" id="name" name="name" required class="field-input" placeholder="Patient name">
        </div>
        <div>
          <label for="age" class="text-[11px] uppercase tracking-wide text-[var(--text-muted)] mb-1 block">Age</label>
          <input type="number" id="age" name="age" min="0" max="150" required class="field-input" placeholder="Age">
        </div>
        <div>
          <label for="gender" class="text-[11px] uppercase tracking-wide text-[var(--text-muted)] mb-1 block">Gender</label>
          <select id="gender" name="gender" required class="field-input">
            <option value="">Select…</option>
            <option value="Male">Male</option>
            <option value="Female">Female</option>
            <option value="Other">Other</option>
          </select>
        </div>
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
        <button type="submit" name="consultation_mode" value="direct" class="w-full py-2.5 rounded-xl bg-gradient-to-b from-[var(--primary)] to-[var(--primary-dark)] text-white font-medium text-sm cursor-pointer hover:opacity-95 transition-opacity">
          Register &amp; Direct Consultation
        </button>
        <button type="submit" name="consultation_mode" value="google_meet" class="w-full py-2.5 rounded-xl border border-[var(--primary)] text-[var(--primary)] font-medium text-sm cursor-pointer hover:bg-[var(--primary)]/10 transition-colors">
          Register &amp; Online Consultation
        </button>
      </div>
    </form>
  </div>

</div>

<?php require __DIR__ . '/../../includes/layout/auth-shell-foot.php'; ?>
