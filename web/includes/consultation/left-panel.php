<!-- ============ LEFT PANEL : VOICE RECORDING ============ -->
<section data-panel="record" class="panel consultation-recorder lg:col-span-3 lg:overflow-y-auto lg:overscroll-contain lg:pr-1 lg:pb-2 space-y-5">

  <div class="card recorder-card p-5 sm:p-6 text-center">
    <div class="flex items-center justify-between mb-4">
      <div class="text-left flex items-start gap-2.5">
        <span class="clinical-panel-icon mt-0.5"><?= icon('stethoscope', 'w-4.5 h-4.5') ?></span>
        <div>
          <h2 class="font-semibold text-sm"><?= $consultation_mode === 'google_meet' ? 'Online Consultation' : 'Voice Recording' ?></h2>
          <p class="text-[10px] text-[var(--text-muted)] mt-0.5">Clinical capture</p>
          <?php if ($consultation_mode === 'google_meet'): ?>
            <p class="text-[10px] text-[var(--text-muted)] mt-0.5">Microphone + Google Meet tab audio</p>
          <?php endif; ?>
        </div>
      </div>
      <span id="rec-state-badge" class="state-badge state-ready">
        <span class="w-1.5 h-1.5 rounded-full bg-current"></span>
        Ready
      </span>
    </div>

    <div class="flex items-center gap-2 mb-5">
      <label for="rec-lang" class="text-[11px] text-[var(--text-muted)] shrink-0">Speaking in</label>
      <select id="rec-lang" class="field-input py-1.5 text-xs">
        <option value="en-IN">English</option>
        <option value="hi-IN">Hindi</option>
        <option value="gu-IN">Gujarati</option>
      </select>
    </div>

    <p id="asr-unsupported" class="hidden text-[11px] text-[var(--accent-amber)] bg-[var(--accent-amber-bg)] rounded-lg px-3 py-2 mb-4 text-left">
      Live voice transcription isn't supported in this browser. Please use Chrome or Edge.
    </p>

    <?php if ($consultation_mode === 'google_meet'): ?>
      <div class="google-meet-connect-card rounded-xl border border-[var(--border)] bg-[var(--surface-2)] p-3 mb-4 text-left">
        <button id="btn-connect-google-meet" type="button" class="google-meet-connect-btn w-full py-2 text-xs" data-state="disconnected" aria-pressed="false">
          Connect Google Meet
        </button>
        <p id="google-meet-status" class="text-[10px] text-[var(--text-muted)] mt-2 leading-relaxed" data-state="disconnected" aria-live="polite">
          Select the active Google Meet tab, then enable <strong>Share tab audio</strong> in the browser prompt.
        </p>
      </div>
    <?php endif; ?>

    <div class="recorder-illustration" aria-hidden="true">
      <span class="recorder-illustration-icon"><?= icon('heart-pulse', 'w-5 h-5') ?></span>
      <span class="recorder-illustration-line"></span>
      <span class="recorder-illustration-icon"><?= icon('wave', 'w-5 h-5') ?></span>
    </div>

    <!-- Mic button -->
    <div class="mic-stage relative w-36 h-36 mx-auto mb-5">
      <span id="mic-pulse" class="absolute inset-0 rounded-full bg-[var(--primary)]/20 hidden"></span>
      <span id="mic-pulse-2" class="absolute inset-0 rounded-full bg-[var(--primary)]/10 hidden"></span>
      <button id="mic-btn" type="button" aria-label="Start recording"
        class="relative w-36 h-36 rounded-full bg-gradient-to-b from-[var(--primary)] to-[var(--primary-dark)] text-white shadow-card flex items-center justify-center cursor-pointer transition-transform duration-200 hover:scale-[1.03] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-[var(--primary)]">
        <?= icon('mic', 'w-12 h-12') ?>
      </button>
    </div>

    <!-- Timer -->
    <p id="rec-timer" class="font-mono text-2xl font-semibold tabular-nums mb-1">00:00</p>
    <p id="rec-caption" class="text-xs text-[var(--text-muted)] mb-4"><?= $consultation_mode === 'google_meet' ? 'Connect Google Meet, then tap the mic to start' : 'Tap the mic to start the consultation' ?></p>

    <!-- Waveform -->
    <div id="waveform" class="flex items-end justify-center gap-[3px] h-10 mb-5" aria-hidden="true">
      <?php for ($i = 0; $i < 28; $i++): ?>
        <span class="wave-bar" style="--d:<?= $i ?>"></span>
      <?php endfor; ?>
    </div>

    <!-- Controls -->
    <div class="grid grid-cols-3 gap-2 mb-3">
      <button id="btn-pause" type="button" disabled
        class="btn-secondary flex flex-col items-center gap-1 py-2.5 text-xs disabled:opacity-40 disabled:cursor-not-allowed">
        <?= icon('pause', 'w-4.5 h-4.5') ?> Pause
      </button>
      <button id="btn-resume" type="button" disabled
        class="btn-secondary flex flex-col items-center gap-1 py-2.5 text-xs disabled:opacity-40 disabled:cursor-not-allowed">
        <?= icon('play', 'w-4.5 h-4.5') ?> Resume
      </button>
      <button id="btn-stop" type="button" disabled
        class="btn-secondary flex flex-col items-center gap-1 py-2.5 text-xs disabled:opacity-40 disabled:cursor-not-allowed">
        <?= icon('stop', 'w-4.5 h-4.5') ?> Stop
      </button>
    </div>

    <div id="upload-zone" class="upload-zone" role="button" tabindex="0" aria-label="Upload an audio recording">
      <input type="file" id="audio-file-input" accept="audio/*" class="hidden">
      <span class="flex items-center justify-center gap-2 text-xs font-medium text-[var(--text-muted)]">
        <?= icon('upload', 'w-4 h-4') ?> <span id="upload-zone-label">Upload Audio (optional)</span>
      </span>
    </div>
    <p class="text-[10px] text-[var(--text-muted)] mt-1.5">Drag &amp; drop, or click to browse — MP3, WAV, M4A, OGG (max 50MB)</p>

    <div id="uploaded-file-card" class="hidden mt-3 text-left rounded-xl border border-[var(--border)] bg-[var(--surface-2)] p-3">
      <div class="flex items-start justify-between gap-2 mb-2">
        <div class="min-w-0">
          <p id="uploaded-file-name" class="text-xs font-medium truncate"></p>
          <p id="uploaded-file-meta" class="text-[10px] text-[var(--text-muted)]"></p>
        </div>
        <button id="uploaded-file-remove" type="button" class="chip-remove shrink-0" aria-label="Remove uploaded file">
          <?= icon('trash', 'w-3.5 h-3.5') ?>
        </button>
      </div>
      <audio id="uploaded-file-audio" controls class="w-full h-9 mb-2"></audio>

      <button id="btn-transcribe-upload" type="button" class="btn-secondary w-full justify-center py-2 text-xs">
        <?= icon('sparkles', 'w-4 h-4') ?> <span id="btn-transcribe-label">Transcribe this recording</span>
      </button>
      <p class="text-[10px] text-[var(--text-muted)] mt-1.5 leading-relaxed">
        Transcribed and structured by the AI backend.
      </p>
    </div>
  </div>

  <?php if (!empty($previous_visits)): ?>
    <!-- Previous Visits -->
    <div class="card p-5 sm:p-6">
      <h2 class="font-semibold text-sm mb-3 flex items-center gap-2">
        <?= icon('clock', 'w-4 h-4 text-[var(--primary)]') ?> Previous Visits
      </h2>
      <ul id="previous-visits-list" class="space-y-2">
        <?php foreach ($previous_visits as $v): ?>
          <?php
            $v_dt = new DateTime($v['visit_datetime'], new DateTimeZone('UTC'));
            $v_dt->setTimezone(new DateTimeZone('Asia/Kolkata'));
          ?>
          <li class="flex items-center justify-between gap-2 text-xs">
            <div class="leading-tight min-w-0">
              <p class="font-medium truncate"><?= htmlspecialchars($v_dt->format('d M Y, h:i A')) ?></p>
              <p class="text-[11px] text-[var(--text-muted)] truncate">Dr. <?= htmlspecialchars($v['doctor_name']) ?> · <?= $v['status'] === 'final' ? 'Finalized' : 'Draft' ?></p>
            </div>
            <a href="/consultation/print.php?visit_id=<?= (int) $v['id'] ?>" target="_blank" class="btn-secondary px-2.5 py-1.5 text-[11px] shrink-0">View</a>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <!-- Voice commands -->
  <div class="card p-5 sm:p-6">
    <h2 class="font-semibold text-sm mb-3 flex items-center gap-2">
      <?= icon('sparkles', 'w-4 h-4 text-[var(--primary)]') ?> Voice Commands
    </h2>
    <ul class="space-y-2">
      <?php foreach ($voice_commands as $cmd): ?>
        <li>
          <button type="button" class="voice-command-btn w-full text-left px-2 py-1.5 rounded-md bg-[var(--surface-2)] hover:bg-[var(--border)] font-mono text-[11px] text-[var(--text)] cursor-pointer transition-colors duration-150" data-command="<?= htmlspecialchars($cmd['command']) ?>">
            "<?= htmlspecialchars($cmd['label']) ?>"
          </button>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>
</section>
