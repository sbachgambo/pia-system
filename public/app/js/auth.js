// Session + lock state for the PWA (brief D4 / §6).
//
// Model: a cached session (auth_cache) can exist without the app being
// "unlocked". A cold start unlocks by either an online login or a local PIN
// check (Argon2id via WASM). Between unlocks the UI is gated. An online login
// is still required at least every `reauth_days` days.

import { UNLOCK_TTL_MS } from './config.js';
import { login as apiLogin, me as apiMe } from './api.js';
import { getSession, setSession, patchSessionOffline, clearSession } from './store.js';
import { idb, STORES } from './idb.js';
import { verifyPin, warmUp } from './argon.js';

let unlockedUntil = 0;

export async function hasSession() {
  return (await getSession()) !== null;
}

export async function currentUser() {
  const s = await getSession();
  return s ? s.user : null;
}

export async function offlineInfo() {
  const s = await getSession();
  return (s && s.offline) || null;
}

export async function pinIsSet() {
  const o = await offlineInfo();
  return !!(o && o.pin_set);
}

export async function reauthDeadline() {
  const o = await offlineInfo();
  return o && o.reauth_deadline ? new Date(o.reauth_deadline) : null;
}

export async function isReauthOverdue() {
  const d = await reauthDeadline();
  return d ? Date.now() > d.getTime() : false;
}

export function isUnlocked() {
  return Date.now() < unlockedUntil;
}

export function markUnlocked() {
  unlockedUntil = Date.now() + UNLOCK_TTL_MS;
}

export function lockNow() {
  unlockedUntil = 0;
}

export function touchUnlock() {
  if (isUnlocked()) unlockedUntil = Date.now() + UNLOCK_TTL_MS;
}

export async function loginOnline(email, password) {
  const bundle = await apiLogin(email, password);
  if (bundle.user && bundle.user.role !== 'inspector') {
    // The field app is for inspectors; office staff use the /console.
    throw Object.assign(new Error('This app is for field inspectors. Use the office console.'), { code: 'not_inspector' });
  }
  await setSession(bundle);
  markUnlocked();
  warmUp();
  return bundle;
}

/** @returns {Promise<{ok:true}|{ok:false}|{unavailable:true, reason?:string}>} */
export async function unlockWithPin(pin) {
  const o = await offlineInfo();
  const res = await verifyPin(pin, o && o.pin_hash);
  if (res.ok) markUnlocked();
  return res;
}

/** Refresh the cached profile/offline block while we have a connection. */
export async function refreshProfile() {
  try {
    const profile = await apiMe();
    if (profile && profile.offline) await patchSessionOffline(profile.offline);
    return profile;
  } catch {
    return null;
  }
}

export async function logout({ wipe = false } = {}) {
  lockNow();
  await clearSession();
  if (wipe) {
    await idb.clear(STORES.inspections);
    await idb.clear(STORES.attachments);
    await idb.clear(STORES.reference);
  }
}
