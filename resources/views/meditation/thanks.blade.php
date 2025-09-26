@extends('layouts.app')

@section('content')
<div class="download-wrap">
  <div class="download-card">
    <div id="poller"
         data-status-url="{{ route('meditation.download-status', $meditation) }}">
    </div>

    <div id="brew-overlay" class="brew__overlay" aria-live="polite" aria-busy="true">
    <div class="brew__panel" role="status">
      <div class="brew__logo" aria-hidden="true">🧘‍♂️</div>
      <h2 class="brew__title">Brewing calming vibes...</h2>
      <p id="brew-msg" class="brew__message">Finding your inner Wi-Fi…</p>

      <div class="spinner" aria-hidden="true"></div>

  <p id="brew-error" class="brew__error hidden" role="alert"></p>

      <p class="brew__hint">You can keep this tab open. We’ll nudge you when it’s ready.</p>
    </div>
  </div>

    

    <div id="ready" class="hidden">
      <a id="dl" href="#" class="btn" download>Download meditation (MP3)</a>
      <p><code id="url"></code></p>
    </div>
  </div>
</div>

<script>
  const statusUrl = document.getElementById('poller').dataset.statusUrl;

  // --- Funny messages rotation ---
  const brewMessages = [
    "Finding your inner Wi-Fi…",
    "Fluffing the meditation cushions…",
    "Warming the singing bowls…",
    "Assembling chill molecules…",
    "Asking your chakras for permission…",
    "Politely shushing stray thoughts…",
    "Steeping tranquillity (do not stir)…",
    "Tuning forks, tuning mood…",
    "Pressing pause on chaos…"
  ];

  // --- Message cycling (spread over ~5 minutes) ---
  let msgIndex = 0;
  const msg = document.getElementById('brew-msg');
  const overlay = document.getElementById('brew-overlay');

  // 9 messages over ~5 minutes => ~33 seconds per message
  const messageInterval = 33000; // 33s

  function rotateMessage() {
    msgIndex = (msgIndex + 1) % brewMessages.length;
    msg.textContent = brewMessages[msgIndex];
  }

  const messageTimer = setInterval(rotateMessage, messageInterval);

  // --- Polling (your original logic, with a couple of tweaks) ---
  let attempts = 0, intervalMs = 5000, timer;
  let consecutiveErrors = 0;
  const startTime = Date.now();
  const maxWaitMs = 20 * 60 * 1000; // 20 minutes safety timeout
  const errEl = document.getElementById('brew-error');
  const spinEl = document.querySelector('#brew-overlay .spinner');

  function showError(message) {
    // Stop timers
    clearInterval(timer);
    clearInterval(messageTimer);

    // Show error UI
    errEl.textContent = message || 'Something went wrong while preparing your meditation.';
    errEl.classList.remove('hidden');

    // Pause spinner animation for visual cue
    if (spinEl) spinEl.classList.add('stopped');
  }

  async function poll() {
    try {
      // Overall timeout guard
      if (Date.now() - startTime > maxWaitMs) {
        showError('There has been an error.');
        return;
      }

      const res = await fetch(statusUrl, { headers: { 'Accept': 'application/json' }});
      if (!res.ok) {
        consecutiveErrors++;
        if (consecutiveErrors >= 3) {
          showError('We’re having trouble reaching the server. Please try again shortly.');
          return;
        }
        throw new Error('Network not ok');
      }
      const data = await res.json();
      consecutiveErrors = 0; // reset on success

      if (data.error || data.status === 'failed') {
        showError(data.error || 'We hit a snag while creating your meditation. Please try again.');
        return;
      }

      if (data.ready && data.meditation_url) {
        // Stop timers and show completion
        clearInterval(timer);
        clearInterval(messageTimer);
        msg.textContent = "Your meditation is ready ✨";

        // Fill download details
        document.getElementById('ready').classList.remove('hidden');
        const a = document.getElementById('dl');
        a.href = data.meditation_url;
        document.getElementById('url').textContent = data.meditation_url;

        // Gentle fade out
        overlay.style.opacity = '1';
        overlay.style.transition = 'opacity .5s ease';
        requestAnimationFrame(() => {
          overlay.style.opacity = '0';
          setTimeout(() => overlay.remove(), 550);
        });
        return;
      }

      attempts++;
      if (attempts === 12) { clearInterval(timer); intervalMs = 8000; timer = setInterval(poll, intervalMs); }
      if (attempts === 24) { clearInterval(timer); intervalMs = 12000; timer = setInterval(poll, intervalMs); }
    } catch (e) {
      console.warn('Polling error:', e);
      // Soft-fail; next tick will either recover or trip the error threshold
    }
  }

  timer = setInterval(poll, intervalMs);
  poll();
</script>

@endsection
