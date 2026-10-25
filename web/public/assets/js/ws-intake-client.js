(function () {
  'use strict';

  /**
   * Thin WebSocket client for the AI intake-call endpoint (/ws/intake).
   * Deliberately separate from ws-stream-client.js (not a modification of it) —
   * that client's dispatch only knows status|interim|final|error and has no
   * control-frame API; the intake protocol has more message types (turn,
   * red_flag, call_status) and needs to SEND control frames (whisper, barge_in,
   * mute/unmute, hold/resume), not just "end". Duplicating a little WS-plumbing
   * boilerplate here is a deliberate trade-off to avoid touching the
   * already-working mic/upload streaming client.
   */

  function createIntakeSession(wsUrl, callbacks) {
    callbacks = callbacks || {};
    var ws = new WebSocket(wsUrl);

    ws.onopen = function () {
      if (callbacks.onOpen) callbacks.onOpen();
    };

    ws.onmessage = function (evt) {
      var data;
      try {
        data = JSON.parse(evt.data);
      } catch (e) {
        return;
      }
      switch (data.type) {
        case 'call_status':
          if (callbacks.onCallStatus) callbacks.onCallStatus(data);
          break;
        case 'turn':
          if (callbacks.onTurn) callbacks.onTurn(data);
          break;
        case 'interim':
          if (callbacks.onInterim) callbacks.onInterim(data);
          break;
        case 'red_flag':
          if (callbacks.onRedFlag) callbacks.onRedFlag(data);
          break;
        case 'final':
          if (callbacks.onFinal) callbacks.onFinal(data);
          break;
        case 'error':
          if (callbacks.onError) callbacks.onError(data);
          break;
      }
    };

    ws.onerror = function () {
      if (callbacks.onError) callbacks.onError({ message: 'WebSocket connection error' });
    };

    ws.onclose = function (evt) {
      if (callbacks.onClose) callbacks.onClose(evt);
    };

    function sendControl(payload) {
      if (ws.readyState === WebSocket.OPEN) {
        ws.send(JSON.stringify(payload));
      }
    }

    return {
      initScript: function (steps, scenarioText, patientName) {
        sendControl({ type: 'init', steps: steps, scenario_text: scenarioText, patient_name: patientName });
      },
      whisper: function (text) { sendControl({ type: 'whisper', text: text }); },
      bargeIn: function () { sendControl({ type: 'barge_in' }); },
      mute: function () { sendControl({ type: 'mute' }); },
      unmute: function () { sendControl({ type: 'unmute' }); },
      hold: function () { sendControl({ type: 'hold' }); },
      resume: function () { sendControl({ type: 'resume' }); },
      end: function () { sendControl({ type: 'end' }); },
      close: function () {
        try { ws.close(); } catch (e) { /* already closed */ }
      },
    };
  }

  window.HimsWsIntakeClient = {
    createIntakeSession: createIntakeSession,
  };
})();
