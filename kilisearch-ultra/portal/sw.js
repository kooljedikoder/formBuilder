// Minimal offline shell for the standalone PWA — caches static assets and
// the offline fallback page only. index.php itself, and every /api/*.php
// call, are deliberately never cached: they carry live session/branding/
// search state that a stale cached copy would get wrong — the tradeoff is
// that a fully offline visit to index.php falls back to a brand-neutral
// static page (offline.html) rather than a stale-but-branded one. Bump
// CACHE_NAME to invalidate old caches after changing this list.
const CACHE_NAME = 'kili-shell-v2';
const SHELL_ASSETS = [
  '../assets/css/kili.css',
  '../assets/js/kili.js',
  'offline.html',
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => cache.addAll(SHELL_ASSETS))
  );
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(keys.filter((key) => key !== CACHE_NAME).map((key) => caches.delete(key)))
    )
  );
  self.clients.claim();
});

self.addEventListener('fetch', (event) => {
  if (event.request.method !== 'GET') {
    return;
  }

  // Navigations (loading the page itself) get a network-first strategy so
  // branding/session are always fresh when online, falling back to the
  // static offline page only when the network is genuinely unreachable.
  // cache: 'no-store' matters here — without it, this fetch can be
  // satisfied by the browser's own HTTP cache even when the real network
  // is down, so the "offline" branch would never trigger.
  if (event.request.mode === 'navigate') {
    event.respondWith(
      fetch(event.request, { cache: 'no-store' }).catch(() => caches.match('offline.html'))
    );
    return;
  }

  const url = new URL(event.request.url);
  const isShellAsset = SHELL_ASSETS.some((asset) => url.pathname.endsWith(asset.replace('../', '/')));
  if (!isShellAsset) {
    return;
  }

  event.respondWith(
    caches.match(event.request).then((cached) => {
      if (cached) return cached;
      return fetch(event.request).then((response) => {
        const copy = response.clone();
        caches.open(CACHE_NAME).then((cache) => cache.put(event.request, copy));
        return response;
      });
    })
  );
});
