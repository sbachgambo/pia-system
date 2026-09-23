// Static configuration for the inspector PWA.
// Same-origin with the API, so the base is just a path.

export const API_BASE = '/api';

// Bump when the app-shell asset list changes so the service worker
// installs a fresh cache and drops the old one.
export const SHELL_VERSION = 'v3';
export const SHELL_CACHE = `pia-shell-${SHELL_VERSION}`;

// How long a successful PIN unlock keeps the UI open before it locks again.
export const UNLOCK_TTL_MS = 15 * 60 * 1000;

// Background Sync tag (Chrome/Android); iOS falls back to manual "Sync now".
export const SYNC_TAG = 'pia-sync';

// Files precached on service-worker install. Keep in sync with what the app
// actually loads. Paths are relative to /app/.
export const SHELL_ASSETS = [
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
