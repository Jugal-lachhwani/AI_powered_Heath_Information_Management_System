<?php
/**
 * includes/core/db.php — PDO SQLite bootstrap.
 *
 * Require this once per request (before any doctor/patient/visit DB access).
 * Defines the global $pdo connection and the HIMS-style ID generators.
 */

date_default_timezone_set('Asia/Kolkata'); // HIMS is an Indian deployment — IST throughout

$pdo = new PDO('sqlite:' . __DIR__ . '/../../data/hims.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = ON;');

$pdo->exec('
    CREATE TABLE IF NOT EXISTS doctors (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        full_name     TEXT NOT NULL,
        email         TEXT NOT NULL UNIQUE,
        password_hash TEXT NOT NULL,
        created_at    TEXT NOT NULL DEFAULT (datetime(\'now\'))
    );
');

$pdo->exec('
    CREATE TABLE IF NOT EXISTS patients (
        id                   INTEGER PRIMARY KEY AUTOINCREMENT,
        uhid                 TEXT NOT NULL UNIQUE,
        name                 TEXT NOT NULL,
        age                  INTEGER NOT NULL,
        gender               TEXT NOT NULL,
        created_by_doctor_id INTEGER NOT NULL REFERENCES doctors(id),
        created_at           TEXT NOT NULL DEFAULT (datetime(\'now\'))
    );
');

$pdo->exec('
    CREATE TABLE IF NOT EXISTS visits (
        id             INTEGER PRIMARY KEY AUTOINCREMENT,
        visit_no       TEXT NOT NULL UNIQUE,
        patient_id     INTEGER NOT NULL REFERENCES patients(id),
        doctor_id      INTEGER NOT NULL REFERENCES doctors(id),
        visit_datetime TEXT NOT NULL DEFAULT (datetime(\'now\')),
        created_at     TEXT NOT NULL DEFAULT (datetime(\'now\'))
    );
');

$pdo->exec('
    CREATE TABLE IF NOT EXISTS consultations (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        visit_id    INTEGER NOT NULL UNIQUE REFERENCES visits(id),
        patient_id  INTEGER NOT NULL REFERENCES patients(id),
        doctor_id   INTEGER NOT NULL REFERENCES doctors(id),
        status      TEXT NOT NULL DEFAULT \'draft\',
        data        TEXT NOT NULL,
        created_at  TEXT NOT NULL DEFAULT (datetime(\'now\')),
        updated_at  TEXT NOT NULL DEFAULT (datetime(\'now\'))
    );
');

// AI outbound intake calls (mocked telephony) — a scripted "agent" call that runs
// BEFORE a visit exists; visit_id stays NULL until a doctor approves the draft.
$pdo->exec('
    CREATE TABLE IF NOT EXISTS intake_calls (
        id                INTEGER PRIMARY KEY AUTOINCREMENT,
        patient_id        INTEGER NOT NULL REFERENCES patients(id),
        doctor_id         INTEGER NOT NULL REFERENCES doctors(id),
        visit_id          INTEGER REFERENCES visits(id),
        script_key        TEXT NOT NULL DEFAULT \'chest_pain_flag\',
        run_id            TEXT,
        status            TEXT NOT NULL DEFAULT \'queued\',
        review_status     TEXT NOT NULL DEFAULT \'pending\',
        current_step      TEXT NOT NULL DEFAULT \'identity\',
        human_taken_over  INTEGER NOT NULL DEFAULT 0,
        transcript        TEXT NOT NULL DEFAULT \'[]\',
        state             TEXT NOT NULL DEFAULT \'{}\',
        red_flags         TEXT NOT NULL DEFAULT \'[]\',
        tasks             TEXT NOT NULL DEFAULT \'[]\',
        started_at        TEXT,
        ended_at          TEXT,
        created_at        TEXT NOT NULL DEFAULT (datetime(\'now\')),
        updated_at        TEXT NOT NULL DEFAULT (datetime(\'now\'))
    );
');

// Audit trail for intake calls: whisper/barge-in/mute/hold/red-flag/approve/discard events.
$pdo->exec('
    CREATE TABLE IF NOT EXISTS intake_call_events (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        intake_call_id  INTEGER NOT NULL REFERENCES intake_calls(id),
        doctor_id       INTEGER REFERENCES doctors(id),
        event_type      TEXT NOT NULL,
        payload         TEXT NOT NULL DEFAULT \'{}\',
        created_at      TEXT NOT NULL DEFAULT (datetime(\'now\'))
    );
');

/**
 * Add a column to an existing table if it isn't already there.
 * SQLite has no "ADD COLUMN IF NOT EXISTS", so we check PRAGMA table_info
 * first — kept idempotent-safe to run on every request, same spirit as the
 * CREATE TABLE IF NOT EXISTS blocks above, without needing a migration runner.
 */
function add_column_if_missing(PDO $pdo, string $table, string $column, string $definition): void
{
    $existing = array_column($pdo->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (!in_array($column, $existing, true)) {
        $pdo->exec("ALTER TABLE $table ADD COLUMN $column $definition");
    }
}

// intake_calls: extra columns for the dashboard/queue/config/analytics/mobile screens —
// added via ALTER TABLE since these were introduced after the table already existed in
// the 1b/1c pass, and SQLite ALTER TABLE can only append columns, not reorder them.
add_column_if_missing($pdo, 'intake_calls', 'source', "TEXT NOT NULL DEFAULT 'manual'"); // manual|doctor_requested|batch
add_column_if_missing($pdo, 'intake_calls', 'requested_by_doctor_id', 'INTEGER REFERENCES doctors(id)');
add_column_if_missing($pdo, 'intake_calls', 'purpose', "TEXT NOT NULL DEFAULT ''");
add_column_if_missing($pdo, 'intake_calls', 'scenario_text', "TEXT NOT NULL DEFAULT ''");
add_column_if_missing($pdo, 'intake_calls', 'language', "TEXT NOT NULL DEFAULT 'en-IN'");
add_column_if_missing($pdo, 'intake_calls', 'scheduled_at', 'TEXT');
add_column_if_missing($pdo, 'intake_calls', 'retry_count', 'INTEGER NOT NULL DEFAULT 0');
add_column_if_missing($pdo, 'intake_calls', 'retry_max', 'INTEGER NOT NULL DEFAULT 0');
add_column_if_missing($pdo, 'intake_calls', 'agent_script_id', 'INTEGER');
add_column_if_missing($pdo, 'intake_calls', 'call_mode', "TEXT NOT NULL DEFAULT 'dynamic'"); // dynamic|fixed — which /ws/intake engine this call uses
add_column_if_missing($pdo, 'intake_calls', 'preferred_time', "TEXT NOT NULL DEFAULT ''"); // free-text hint from a doctor-requested call (1i) — not enforced by any scheduler

// doctor|admin — admins get /admin/telephony.php + /admin/agent-config.php (clinic-wide config
// that affects every doctor's calls), everyone else is a regular doctor. See
// /auth/register.php for how the very first account on a fresh install becomes admin.
add_column_if_missing($pdo, 'doctors', 'role', "TEXT NOT NULL DEFAULT 'doctor'");

// One-time migration for installs that already had doctor accounts before the role
// column existed: everyone defaulted to 'doctor' above, which would leave nobody able
// to reach /admin/telephony.php/agent-config.php. Promote the earliest-created account.
// No-ops on every later request once at least one admin exists.
if ((int) $pdo->query("SELECT COUNT(*) FROM doctors WHERE role = 'admin'")->fetchColumn() === 0) {
    $earliestId = $pdo->query('SELECT id FROM doctors ORDER BY created_at, id LIMIT 1')->fetchColumn();
    if ($earliestId) {
        $pdo->prepare("UPDATE doctors SET role = 'admin' WHERE id = ?")->execute([$earliestId]);
    }
}
add_column_if_missing($pdo, 'intake_calls', 'ai_original_state', "TEXT NOT NULL DEFAULT ''");

add_column_if_missing($pdo, 'patients', 'ai_call_consent', 'INTEGER NOT NULL DEFAULT 1');

// Doctor-authored agent script (1e) — exactly one row has status='published' at a time,
// enforced in app code (/api/admin/publish-agent-script.php), not a DB constraint.
$pdo->exec('
    CREATE TABLE IF NOT EXISTS agent_scripts (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        version       INTEGER NOT NULL DEFAULT 1,
        status        TEXT NOT NULL DEFAULT \'draft\',
        created_at    TEXT NOT NULL DEFAULT (datetime(\'now\')),
        published_at  TEXT
    );
');

$pdo->exec('
    CREATE TABLE IF NOT EXISTS agent_script_steps (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        script_id    INTEGER NOT NULL REFERENCES agent_scripts(id),
        step_key     TEXT NOT NULL,
        position     INTEGER NOT NULL DEFAULT 0,
        title        TEXT NOT NULL DEFAULT \'\',
        prompt_text  TEXT NOT NULL DEFAULT \'\',
        fills_field  TEXT NOT NULL DEFAULT \'\',
        required     INTEGER NOT NULL DEFAULT 0,
        locked       INTEGER NOT NULL DEFAULT 0,
        created_at   TEXT NOT NULL DEFAULT (datetime(\'now\'))
    );
');

// Agent voice/persona settings — a singleton row (id=1). The 6 "always on" guardrail
// bullets and the emergency-rule text are NOT here — they're fixed/read-only, baked
// into the agent's turn-generation prompt as constants, not doctor-editable this pass.
$pdo->exec('
    CREATE TABLE IF NOT EXISTS agent_settings (
        id                    INTEGER PRIMARY KEY CHECK (id = 1),
        persona_voice         TEXT NOT NULL DEFAULT \'Asha - warm, female\',
        speaking_speed        REAL NOT NULL DEFAULT 0.9,
        pause_before_replying REAL NOT NULL DEFAULT 0.6,
        allow_interrupt       INTEGER NOT NULL DEFAULT 1,
        updated_at            TEXT NOT NULL DEFAULT (datetime(\'now\'))
    );
');
$pdo->exec("INSERT OR IGNORE INTO agent_settings (id) VALUES (1)");

// Telephony admin (1h) — genuinely stored/editable, but NOT enforced anywhere in the
// actual (mocked) call flow: there is no real telephony provider behind this yet.
$pdo->exec('
    CREATE TABLE IF NOT EXISTS telephony_numbers (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        number     TEXT NOT NULL,
        used_for   TEXT NOT NULL DEFAULT \'\',
        caller_id  TEXT NOT NULL DEFAULT \'\',
        lines      INTEGER NOT NULL DEFAULT 1,
        status     TEXT NOT NULL DEFAULT \'active\',
        created_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
    );
');

$pdo->exec('
    CREATE TABLE IF NOT EXISTS telephony_settings (
        id                 INTEGER PRIMARY KEY CHECK (id = 1),
        calling_window_json TEXT NOT NULL DEFAULT \'{}\',
        retention_days     INTEGER NOT NULL DEFAULT 90,
        mask_numbers       INTEGER NOT NULL DEFAULT 1,
        sms_sender_id      TEXT NOT NULL DEFAULT \'\',
        updated_at         TEXT NOT NULL DEFAULT (datetime(\'now\'))
    );
');
$pdo->exec("INSERT OR IGNORE INTO telephony_settings (id) VALUES (1)");

/**
 * Seed one published agent script mirroring the fixed scripts' structure, so
 * dynamic-mode calls have sensible content immediately. Only runs once — no-ops
 * if any script already exists (doctor-authored or from a prior seed).
 */
function seed_default_agent_script(PDO $pdo): void
{
    $count = (int) $pdo->query('SELECT COUNT(*) FROM agent_scripts')->fetchColumn();
    if ($count > 0) {
        return;
    }

    $pdo->exec("INSERT INTO agent_scripts (version, status, published_at) VALUES (1, 'published', datetime('now'))");
    $scriptId = (int) $pdo->lastInsertId();

    // Step keys match the existing 7-step stepper (/assets/js/intake-monitor.js's
    // STEP_ORDER) exactly, so the default script needs no changes to that widget.
    // Past/family history is folded into the "history" step's instruction rather
    // than added as its own step, to stay within that fixed 7-key set.
    $steps = [
        ['identity',  'Verify identity', 'Ask "Am I speaking with [patient_name]? I\'m calling from the hospital on behalf of [doctor]."', '', 1, 1],
        ['consent',   'Recording consent', 'Ask for permission to record the call, explaining it is shared only with their doctor.', '', 1, 1],
        ['complaint', 'Chief complaint', 'Ask what is troubling them the most today. Let them finish. Never suggest a symptom they did not mention.', 'chief_complaint', 1, 0],
        ['history',   'History of present illness', 'Ask about onset, duration, severity, triggers, relief, and any relevant past illnesses, surgeries, or family history.', 'history_of_present_illness', 0, 0],
        ['red_flags', 'Red flag screen', 'Ask specifically about chest pain, breathlessness, bleeding, sudden weakness, or thoughts of self-harm.', '', 1, 0],
        ['meds',      'Medication & allergies', 'Ask what medication they currently take and whether they have any drug allergies. Repeat drug names back to confirm.', 'current_medication', 0, 0],
        ['wrapup',    'Wrap up', 'Thank the patient, confirm their information has been shared with the doctor, and end the call.', '', 0, 0],
    ];
    $stmt = $pdo->prepare('
        INSERT INTO agent_script_steps (script_id, step_key, position, title, prompt_text, fills_field, required, locked)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ');
    foreach ($steps as $i => $step) {
        [$stepKey, $title, $promptText, $fillsField, $required, $locked] = $step;
        $stmt->execute([$scriptId, $stepKey, $i, $title, $promptText, $fillsField, $required, $locked]);
    }
}
seed_default_agent_script($pdo);

/**
 * Return the id of the agent script currently being edited in 1e (/admin/agent-config.php),
 * creating one by cloning the published script's steps if no draft exists yet.
 * 1e always edits this draft — never the published script directly — so a
 * published call in progress is never affected mid-edit.
 */
function get_or_create_draft_script(PDO $pdo): int
{
    $draftId = $pdo->query("SELECT id FROM agent_scripts WHERE status = 'draft' ORDER BY id DESC LIMIT 1")->fetchColumn();
    if ($draftId) {
        return (int) $draftId;
    }

    $published = $pdo->query("SELECT * FROM agent_scripts WHERE status = 'published' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $version = $published ? (int) $published['version'] + 1 : 1;

    $pdo->prepare('INSERT INTO agent_scripts (version, status) VALUES (?, \'draft\')')->execute([$version]);
    $draftId = (int) $pdo->lastInsertId();

    if ($published) {
        $stmt = $pdo->prepare('
            SELECT step_key, position, title, prompt_text, fills_field, required, locked
            FROM agent_script_steps WHERE script_id = ? ORDER BY position
        ');
        $stmt->execute([(int) $published['id']]);
        $insert = $pdo->prepare('
            INSERT INTO agent_script_steps (script_id, step_key, position, title, prompt_text, fills_field, required, locked)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ');
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $step) {
            $insert->execute([
                $draftId, $step['step_key'], $step['position'], $step['title'],
                $step['prompt_text'], $step['fills_field'], $step['required'], $step['locked'],
            ]);
        }
    }

    return $draftId;
}

/**
 * Generate a HIMS-style UHID for a newly-inserted patient row and persist it.
 * Called immediately after INSERT, using the new row's lastInsertId.
 */
function generate_uhid(PDO $pdo, int $patientId): string
{
    $uhid = 'UHID-' . date('Y') . '-' . str_pad((string) $patientId, 5, '0', STR_PAD_LEFT);
    $stmt = $pdo->prepare('UPDATE patients SET uhid = ? WHERE id = ?');
    $stmt->execute([$uhid, $patientId]);
    return $uhid;
}

/**
 * Generate a HIMS-style visit number for a newly-inserted visit row and persist it.
 * Offset by 100000 so early visits still look like plausible 6-digit HIMS visit numbers.
 */
function generate_visit_no(PDO $pdo, int $visitId): string
{
    $visitNo = 'V-' . str_pad((string) ($visitId + 100000), 6, '0', STR_PAD_LEFT);
    $stmt = $pdo->prepare('UPDATE visits SET visit_no = ? WHERE id = ?');
    $stmt->execute([$visitNo, $visitId]);
    return $visitNo;
}

/**
 * Create a new visit for a patient and return its id.
 * Shared by /patients/select.php (doctor manually starts a visit) and
 * /api/intake/approve-call.php (a visit is auto-created when an AI intake
 * call draft is approved and no visit exists yet for it).
 */
function start_visit_for_patient(PDO $pdo, int $patientId, int $doctorId): int
{
    $stmt = $pdo->prepare(
        "INSERT INTO visits (visit_no, patient_id, doctor_id, visit_datetime) VALUES ('', ?, ?, datetime('now'))"
    );
    $stmt->execute([$patientId, $doctorId]);
    $visitId = (int) $pdo->lastInsertId();
    generate_visit_no($pdo, $visitId);
    return $visitId;
}
