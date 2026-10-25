<!-- ============ BOTTOM ACTION BAR ============ -->
<div class="h-20 lg:hidden"></div>
<footer class="consultation-action-bar fixed bottom-0 inset-x-0 z-40 lg:static lg:inset-auto lg:shrink-0 border-t border-[var(--border)] bg-[var(--surface)]/95 backdrop-blur-md">
  <div class="mx-auto max-w-[1600px] px-4 sm:px-6 lg:px-8 py-3">
    <div class="flex items-center gap-2 overflow-x-auto no-scrollbar">
      <button type="button" id="btn-save-draft" class="action-btn action-btn-ghost">
        <?= icon('save', 'w-4.5 h-4.5') ?> <span>Save Draft</span>
      </button>
      <button type="button" id="btn-save-consultation" class="action-btn action-btn-primary">
        <?= icon('check-circle', 'w-4.5 h-4.5') ?> <span>Save Consultation</span>
      </button>
      <button type="button" id="btn-print" class="action-btn action-btn-ghost">
        <?= icon('printer', 'w-4.5 h-4.5') ?> <span>Print Prescription</span>
      </button>
      <button type="button" id="btn-generate-pdf" class="action-btn action-btn-ghost">
        <?= icon('download', 'w-4.5 h-4.5') ?> <span>Generate PDF</span>
      </button>
      <button type="button" id="btn-email" class="action-btn action-btn-ghost">
        <?= icon('mail', 'w-4.5 h-4.5') ?> <span>Send Email</span>
      </button>
      <button type="button" id="btn-sms" class="action-btn action-btn-ghost">
        <?= icon('phone', 'w-4.5 h-4.5') ?> <span>Send SMS</span>
      </button>
      <div class="flex-1 min-w-[8px]"></div>
      <a href="/patients/select.php" class="action-btn action-btn-cta">
        <span>Next Patient</span> <?= icon('arrow-right', 'w-4.5 h-4.5') ?>
      </a>
    </div>
  </div>
</footer>

<div id="toast" class="toast" role="status" aria-live="polite"></div>
