(function () {
  'use strict';

  /**
   * Shared transport for talking to the AI_backend WebSocket streaming
   * endpoint (/ws/stream). Used by both the live-mic recording flow and the
   * file-upload flow in app.js — neither one duplicates WS/PCM logic.
   */

  function floatTo16BitPCM(float32Array) {
    var out = new Int16Array(float32Array.length);
    for (var i = 0; i < float32Array.length; i++) {
      var s = Math.max(-1, Math.min(1, float32Array[i]));
      out[i] = s < 0 ? s * 0x8000 : s * 0x7fff;
    }
    return out;
  }

  function decodeFileToPCM16(arrayBuffer, targetSampleRate) {
    var AudioContextImpl = window.AudioContext || window.webkitAudioContext;
    var tmpCtx = new AudioContextImpl();

    return tmpCtx.decodeAudioData(arrayBuffer.slice(0))
      .then(function (decoded) {
        var frameCount = Math.ceil(decoded.duration * targetSampleRate);
        var offlineCtx = new OfflineAudioContext(1, frameCount, targetSampleRate);
        var src = offlineCtx.createBufferSource();
        src.buffer = decoded;
        src.connect(offlineCtx.destination);
        src.start(0);
        return offlineCtx.startRendering();
      })
      .then(function (rendered) {
        return floatTo16BitPCM(rendered.getChannelData(0));
      })
      .finally(function () {
        try { tmpCtx.close(); } catch (e) { /* already closed */ }
      });
  }

  /**
   * Mix one or more MediaStreams into the mono PCM16 stream accepted by the
   * existing /ws/stream transport.  Sources are intentionally managed by key
   * so another call provider (Zoom, Teams, a desktop capture bridge, etc.) can
   * be attached without changing the WebSocket or backend pipeline.
   */
  function createPCM16Mixer(audioContext, onPCM16) {
    var sources = {};
    var mixBus = audioContext.createGain();
    var processor = audioContext.createScriptProcessor(4096, 1, 1);
    var muteNode = audioContext.createGain();

    // Explicit mono conversion makes the WebSocket contract stable even when
    // a shared browser tab supplies stereo audio.
    mixBus.channelCount = 1;
    mixBus.channelCountMode = 'explicit';
    mixBus.channelInterpretation = 'speakers';
    muteNode.gain.value = 0;

    processor.onaudioprocess = function (event) {
      onPCM16(floatTo16BitPCM(event.inputBuffer.getChannelData(0)));
    };
    mixBus.connect(processor);
    processor.connect(muteNode);
    muteNode.connect(audioContext.destination);

    function rebalance() {
      var ids = Object.keys(sources);
      var level = ids.length > 1 ? 1 / ids.length : 1;
      ids.forEach(function (id) { sources[id].gain.gain.value = level; });
    }

    function removeSource(id) {
      var item = sources[id];
      if (!item) return;
      try { item.node.disconnect(); } catch (e) { /* already disconnected */ }
      try { item.gain.disconnect(); } catch (e) { /* already disconnected */ }
      delete sources[id];
      rebalance();
    }

    return {
      addSource: function (id, stream) {
        removeSource(id);
        var node = audioContext.createMediaStreamSource(stream);
        var gain = audioContext.createGain();
        gain.channelCount = 1;
        gain.channelCountMode = 'explicit';
        gain.channelInterpretation = 'speakers';
        node.connect(gain);
        gain.connect(mixBus);
        sources[id] = { node: node, gain: gain };
        rebalance();
      },
      removeSource: removeSource,
      close: function () {
        Object.keys(sources).forEach(removeSource);
        try { processor.disconnect(); } catch (e) { /* already disconnected */ }
        processor.onaudioprocess = null;
        try { mixBus.disconnect(); } catch (e) { /* already disconnected */ }
        try { muteNode.disconnect(); } catch (e) { /* already disconnected */ }
      },
    };
  }

  function createSession(wsUrl, callbacks) {
    callbacks = callbacks || {};
    var ws = new WebSocket(wsUrl);
    ws.binaryType = 'arraybuffer';
    var sendQueue = [];
    var endRequested = false;

    ws.onopen = function () {
      sendQueue.forEach(function (buf) { ws.send(buf); });
      sendQueue = [];
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
        case 'status':
          if (callbacks.onStatus) callbacks.onStatus(data);
          break;
        case 'interim':
          if (callbacks.onInterim) callbacks.onInterim(data);
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

    return {
      sendPCM16: function (arrayBufferOrInt16) {
        if (endRequested) return;
        var buf = arrayBufferOrInt16.buffer || arrayBufferOrInt16;
        if (ws.readyState === WebSocket.OPEN) {
          ws.send(buf);
        } else {
          sendQueue.push(buf);
        }
      },
      end: function () {
        // Stop adds the end marker after queued PCM even when the WebSocket is
        // still connecting. The backend can then finish STT/LLM processing and
        // send its final result before either side closes the connection.
        if (endRequested) return;
        endRequested = true;
        var endMessage = JSON.stringify({ type: 'end' });
        if (ws.readyState === WebSocket.OPEN) {
          ws.send(endMessage);
        } else if (ws.readyState === WebSocket.CONNECTING) {
          sendQueue.push(endMessage);
        }
      },
      close: function () {
        try { ws.close(); } catch (e) { /* already closed */ }
      },
      bufferedAmount: function () {
        return ws.bufferedAmount;
      },
    };
  }

  window.HimsWsStream = {
    floatTo16BitPCM: floatTo16BitPCM,
    decodeFileToPCM16: decodeFileToPCM16,
    createPCM16Mixer: createPCM16Mixer,
    createSession: createSession,
  };
})();
