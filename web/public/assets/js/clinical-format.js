(function () {
  'use strict';

  /**
   * Shared clinical-formatting helpers used by app.js (main consultation UI)
   * and the AI intake-call monitor/review pages, so confidence-badge/bar
   * rendering has one source of truth instead of being duplicated per page.
   */

  function confidenceLevel(value) {
    if (value >= 0.8) return 'high';
    if (value >= 0.4) return 'mid';
    return 'low';
  }

  function escapeHtml(str) {
    str = String(str || '');
    return str.replace(/[<>&]/g, function (c) { return { '<': '&lt;', '>': '&gt;', '&': '&amp;' }[c]; });
  }

  /**
   * Append one confidence row (label + percentage badge + bar) to `container`.
   * options.lowConfidenceIconHtml: optional HTML string shown before the label
   * when the level is "low" (callers own their own icon markup/dependency).
   */
  function renderConfidenceRow(container, label, confidence, options) {
    options = options || {};
    var raw = typeof confidence === 'number' ? confidence : 0;
    var pct = Math.round(raw * 100);
    var level = confidenceLevel(raw);

    var row = document.createElement('div');

    var head = document.createElement('div');
    head.className = 'flex items-center justify-between text-xs mb-1.5';

    var labelEl = document.createElement('span');
    labelEl.className = 'font-medium flex items-center gap-1.5';
    if (level === 'low' && options.lowConfidenceIconHtml) {
      labelEl.innerHTML = options.lowConfidenceIconHtml;
    }
    labelEl.appendChild(document.createTextNode(label));

    var pctEl = document.createElement('span');
    pctEl.className = 'confidence-pct confidence-' + level;
    pctEl.textContent = pct + '%';

    head.appendChild(labelEl);
    head.appendChild(pctEl);

    var track = document.createElement('div');
    track.className = 'confidence-track';
    var fill = document.createElement('div');
    fill.className = 'confidence-fill confidence-' + level;
    fill.style.setProperty('--val', pct + '%');
    track.appendChild(fill);

    row.appendChild(head);
    row.appendChild(track);
    container.appendChild(row);
    return row;
  }

  window.HimsClinicalFormat = {
    confidenceLevel: confidenceLevel,
    escapeHtml: escapeHtml,
    renderConfidenceRow: renderConfidenceRow,
  };
})();
