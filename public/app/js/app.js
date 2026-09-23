// Router + bootstrap for the inspector PWA.

import { hasSession, isUnlocked, pinIsSet, touchUnlock } from './auth.js';
import { isArgonPresent, warmUp } from './argon.js';
import { setConnDot, toast } from './ui.js';
import { syncNow } from './sync.js';
import { SYNC_TAG } from './config.js';
import { showLogin } from './views/login.js';
import { showUnlock } from './views/unlock.js';
import { showList } from './views/list.js';
import { showInspection } from './views/inspection.js';
import { showSettings } from './views/settings.js';

let resolving = false;

export function navigate(path) {
  const target = '#' + path;
  if (location.hash === target) resolve();
  else location.hash = target;
}

function parseRoute() {
  const raw = (location.hash || '#/').slice(1);
  const [path, ...rest] = raw.split('/').filter(Boolean);
  if (!path) return { name: 'home' };
  if (path === 'login') return { name: 'login' };
  if (path === 'unlock') return { name: 'unlock' };
  if (path === 'settings') return { name: 'settings' };
  if (path === 'inspection' && rest[0]) return { name: 'inspection', uuid: rest[0] };
  return { name: 'home' };
}

async function resolve() {
  if (resolving) return;
  resolving = true;
  try {
    const route = parseRoute();
    const authed = await hasSession();

    if (!authed) {
      if (route.name !== 'login') return navigate('/login');
      return showLogin();
    }

    if (!isUnlocked()) {
      const canPin = (await pinIsSet()) && isArgonPresent();
      if (route.name === 'unlock' && canPin) return showUnlock();
      if (canPin) return navigate('/unlock');
      // No offline PIN available — must log in online again.
      if (route.name !== 'login') return navigate('/login');
      return showLogin();
    }

    touchUnlock();

    switch (route.name) {
      case 'login': return navigate('/');
      case 'unlock': return navigate('/');
      case 'settings': return showSettings();
      case 'inspection': return showInspection(route.uuid);
      case 'home':
      default: return showList();
    }
  } catch (err) {
    console.error('route error', err);
    toast('Something went wrong loading that screen.', 'err');
  } finally {
    resolving = false;
  }
}

function wireConnectivity() {
  const paint = () => setConnDot(navigator.onLine !== false);
  paint();
  window.addEventListener('online', async () => {
    paint();
    toast('Back online — syncing…', 'info');
    try { await syncNow({ silent: true }); } catch { /* ignore */ }
    resolve();
  });
  window.addEventListener('offline', () => { paint(); toast('You are offline. Your work is saved on this device.', 'warn'); });
}

async function registerSW() {
  if (!('serviceWorker' in navigator)) return;
  try {
    const reg = await navigator.serviceWorker.register('./sw.js');
    // Ask for a one-off background sync when supported (Chrome/Android).
    if ('sync' in reg) {
      try { await reg.sync.register(SYNC_TAG); } catch { /* not critical */ }
    }
    navigator.serviceWorker.addEventListener('message', (e) => {
      if (e.data && e.data.type === 'run-sync') {
        syncNow({ silent: true }).then(() => resolve()).catch(() => {});
      }
    });
  } catch (err) {
    console.warn('SW registration failed', err);
  }
}

function start() {
  wireConnectivity();
  registerSW();
  if (isArgonPresent()) warmUp();
  resolve();
}

window.addEventListener('hashchange', resolve);
// A module script runs once the page is parsed, and DOMContentLoaded may
// already have fired by then — waiting for it alone could leave the app on
// "Loading…" forever. Start now if the DOM is ready, otherwise on the event.
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', start, { once: true });
} else {
  start();
}
