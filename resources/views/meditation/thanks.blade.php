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

      <div class="brew__progress" aria-label="Progress">
        <div id="brew-bar" class="brew__progress-bar" style="width: 0%"></div>
      </div>

      <div class="brew__percent">
        <span id="brew-percent">0%</span>
      </div>

      <p class="brew__hint">You can keep this tab open. We’ll nudge you when it’s ready.</p>
    </div>
  </div>

    <div id="waiting" aria-live="polite">
      <div class="status-title">Brewing calming vibes<span class="dots"></span></div>
      <p class="status-subtle">Your personalised meditation is being prepared.</p>
      <div class="spinner" aria-hidden="true"></div>
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

  // --- Faux progress controller ---
  let fakeProgress = 0;
  let readySignal = false;
  let msgIndex = 0;
  const bar = document.getElementById('brew-bar');
  const pct = document.getElementById('brew-percent');
  const msg = document.getElementById('brew-msg');
  const overlay = document.getElementById('brew-overlay');

  // Progress moves fast at first, then slower, and never passes 95% until ready.
  function tickProgress() {
    if (readySignal) return; // final animation handled on success
    const cap = 95;

    // ease: bigger steps early, tiny ones later + a smidge of randomness
    const gap = Math.max(1, cap - fakeProgress);
    const step = Math.max(0.4, Math.min(6, gap / 8)) + (Math.random() * 0.8 - 0.2);
    fakeProgress = Math.min(cap, fakeProgress + step);

    bar.style.width = fakeProgress.toFixed(1) + '%';
    pct.textContent = Math.round(fakeProgress) + '%';
  }

  // Rotate messages every ~4s
  function rotateMessage() {
    msgIndex = (msgIndex + 1) % brewMessages.length;
    msg.textContent = brewMessages[msgIndex];
  }

  const progressTimer = setInterval(tickProgress, 700);
  const messageTimer = setInterval(rotateMessage, 4000);

  // --- Polling (your original logic, with a couple of tweaks) ---
  let attempts = 0, intervalMs = 5000, timer;

  async function poll() {
    try {
      const res = await fetch(statusUrl, { headers: { 'Accept': 'application/json' }});
      if (!res.ok) throw new Error('Network not ok');
      const data = await res.json();

      if (data.ready && data.meditation_url) {

        document.querySelector('.spinner')?.classList.add('hide');
        setTimeout(() => {
          document.querySelector('.spinner')?.remove();
        }, 500); // remove from DOM after fade

        // Mark as ready: blast progress to 100 and fade overlay
        readySignal = true;
        bar.style.width = '100%';
        pct.textContent = '100%';
        msg.textContent = "Your meditation is ready ✨";

        // Fill download details
        document.getElementById('ready').classList.remove('hidden');
        const a = document.getElementById('dl');
        a.href = data.meditation_url;
        document.getElementById('url').textContent = data.meditation_url;

        // Tidy timers
        clearInterval(timer);
        clearInterval(progressTimer);
        clearInterval(messageTimer);

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
      // If there’s a blip, nudge progress a tad so it still feels alive
      fakeProgress = Math.min(95, fakeProgress + 1);
    }
  }

  timer = setInterval(poll, intervalMs);
  poll();
</script>

@endsection
