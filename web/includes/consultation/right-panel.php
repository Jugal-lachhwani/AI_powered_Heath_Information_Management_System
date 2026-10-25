<!-- ============ RIGHT PANEL : AI ASSISTANT ============ -->
<section data-panel="assistant" class="panel consultation-assistant lg:col-span-3 lg:overflow-y-auto lg:overscroll-contain lg:pr-1 lg:pb-2 space-y-5">

  <!-- Live Transcript -->
  <div class="card assistant-live-card p-5 sm:p-6">
    <div class="flex items-center justify-between mb-3">
      <h2 class="font-semibold text-sm flex items-center gap-2">
        <span class="relative flex w-2 h-2">
          <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-[var(--accent-red)] opacity-60"></span>
          <span class="relative inline-flex rounded-full w-2 h-2 bg-[var(--accent-red)]"></span>
        </span>
        Live Transcript
      </h2>
    </div>
    <div id="transcript-box" class="transcript-box">
      <p id="transcript-placeholder" class="text-[var(--text-muted)]">Transcript will appear here once you start recording…</p>
      <p class="transcript-line" id="transcript-live"></p>
    </div>
    <button id="btn-generate-consultation" type="button" class="btn-secondary w-full justify-center py-2 text-xs mt-3">
      <?= icon('sparkles', 'w-4 h-4') ?> <span id="btn-generate-label">Generate AI Consultation</span>
    </button>
    <p id="ai-generate-status" class="text-[10px] text-[var(--text-muted)] mt-1.5 leading-relaxed">
      Uses the local FastAPI + Ollama backend.
    </p>
  </div>

  <!-- AI Suggestions -->
  <div class="card assistant-suggestion-card p-5 sm:p-6">
    <h2 class="font-semibold text-sm mb-3 flex items-center gap-2">
      <span class="clinical-panel-icon"><?= icon('bot', 'w-4.5 h-4.5') ?></span>
      AI Suggestions
    </h2>
    <div id="ai-suggestions-list" class="space-y-3"></div>

    <div id="drug-interaction-alert" class="mt-4"></div>
  </div>

  <!-- Confidence Score -->
  <div class="card confidence-card p-5 sm:p-6">
    <h2 class="font-semibold text-sm mb-1 flex items-center gap-2">
      <span class="clinical-panel-icon"><?= icon('shield', 'w-4.5 h-4.5') ?></span>
      Confidence Score
    </h2>
    <p class="text-[11px] text-[var(--text-muted)] mb-4">Low-confidence sections are highlighted for your review.</p>
    <div id="confidence-scores-list" class="space-y-3.5"></div>
  </div>

  <!-- Live Usage -->
  <div class="card usage-card p-5 sm:p-6">
    <div class="flex items-center justify-between mb-3">
      <h2 class="font-semibold text-sm flex items-center gap-2">
        <span class="clinical-panel-icon"><?= icon('activity', 'w-4.5 h-4.5') ?></span>
        Token Usage
      </h2>
      <span id="usage-final-badge" class="text-[11px] text-[var(--text-muted)]">waiting</span>
    </div>
    <div class="usage-grid">
      <span>Total API calls</span><strong id="usage-api-calls">0</strong>
      <span>LLM calls</span><strong id="usage-llm-calls">0</strong>
      <span>STT calls</span><strong id="usage-stt-calls">0</strong>
      <span>Input tokens</span><strong id="usage-input-tokens">0</strong>
      <span>Output tokens</span><strong id="usage-output-tokens">0</strong>
      <span>Total tokens</span><strong id="usage-total-tokens">0</strong>
      <span>LLM time</span><strong id="usage-llm-time">0.0s</strong>
    </div>
    <p id="usage-note" class="text-[11px] text-[var(--text-muted)] mt-2">Updates while the backend processes the conversation.</p>
  </div>

</section>
