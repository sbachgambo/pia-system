/* Service worker for the inspector PWA (brief §6).
 *
 * Scope is /app/ (it is registered as ./sw.js from /app/index.html), so it
 * only ever sees requests under /app/. API calls to /api/* are outside scope
 * and pass straight through untouched — auth and data sync are the app's job
 * via IndexedDB, never the HTTP cache.
 *
 * Keep SHELL_VERSION / SHELL_ASSETS in step with js/config.js.
 */

const SHELL_VERSION = 'v3';
const SHELL_CACHE = `pia-shell-${SHELL_VERSION}`;
const SYNC_TAG = 'pia-sync';

const SHELL_ASSETS = [
  './',
  './index.html',
  './offline.html',
  './manifest.webmanifest',
  './css/app.css',
  './js/config.js',
  './js/util.js',
  './js/idb.js',
  './js/store.js',
  './js/api.js',
  './js/argon.js',
  './js/auth.js',
  './js/sync.js',
  './js/ui.js',
  './js/app.js',
  './js/views/login.js',
  './js/views/unlock.js',
  './js/views/list.js',
  './js/views/inspection.js',
  './js/views/settings.js',
  './vendor/argon2/argon2-bundled.min.js',
  './vendor/argon2/argon2.wasm',
  './icons/icon-192.png',
  './icons/icon-512.png',
  './icons/icon-maskable-512.png',
  './icons/apple-touch-icon.png',
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(SHELL_CACHE).then((cache) => cache.addAll(SHELL_ASSETS)).then(() => self.skipWaiting()),
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k !== SHELL_CACHE).map((k) => caches.delete(k))))
      .then(() => self.clients.claim()),
  );
});

self.addEventListener('fetch', (event) => {
  const { request } = event;
  if (request.method !== 'GET') return;

  const url = new URL(request.url);
  if (url.origin !== self.location.origin) return;
  if (url.pathname.startsWith('/api/')) return; // never cache the API

  if (request.mode === 'navigate') {
    event.respondWith(
      fetch(request)
        .catch(() => caches.match('./index.html', { ignoreSearch: true })
          .then((r) => r || caches.match('./offline.html'))),
    );
    return;
  }

  event.respondWith(
    caches.match(request, { ignoreSearch: true }).then((cached) => {
      if (cached) return cached;
      return fetch(request).then((resp) => {
        if (resp && resp.ok && resp.type === 'basic') {
          const copy = resp.clone();
          caches.open(SHELL_CACHE).then((cache) => cache.put(request, copy));
        }
        return resp;
      });
    }),
  );
});

// Background Sync (Chrome/Android). We don't hold the auth token here — the
// window clients do — so we just wake them to run their own sync. iOS Safari
// has no Background Sync; the app falls back to "Sync now" + sync-on-reconnect.
self.addEventListener('sync', (event) => {
  if (event.tag !== SYNC_TAG) return;
  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clients) => {
      clients.forEach((c) => c.postMessage({ type: 'run-sync' }));
    }),
  );
});

self.addEventListener('message', (event) => {
  if (event.data && event.data.type === 'skip-waiting') self.skipWaiting();
});
