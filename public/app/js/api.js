// Thin API client: attaches the bearer token, transparently refreshes it once
// on a 401, and turns "no network" into a typed OfflineError the UI can branch
// on instead of a raw TypeError.

import { API_BASE } from './config.js';
import { getSession, setSession, clearSession } from './store.js';

export class OfflineError extends Error {
  constructor(msg = 'You are offline.') {
    super(msg);
    this.name = 'OfflineError';
  }
}

export class ApiError extends Error {
  constructor(status, code, message, body) {
    super(message || code || `HTTP ${status}`);
    this.name = 'ApiError';
    this.status = status;
    this.code = code;
    this.body = body;
  }
}

let refreshInFlight = null;

async function rawFetch(path, { method = 'GET', body, token, headers = {} } = {}) {
  const opts = { method, headers: { ...headers } };
  if (token) opts.headers['Authorization'] = `Bearer ${token}`;
  if (body !== undefined && !(body instanceof Blob) && !(body instanceof ArrayBuffer)) {
    opts.headers['Content-Type'] = 'application/json';
    opts.body = JSON.stringify(body);
  } else if (body !== undefined) {
    opts.body = body;
  }

  let res;
  try {
    res = await fetch(API_BASE + path, opts);
  } catch (err) {
    throw new OfflineError();
  }
  return res;
}

async function parse(res) {
  const text = await res.text();
  let data = null;
  try {
    data = text ? JSON.parse(text) : null;
  } catch {
    data = text;
  }
  if (!res.ok) {
    const err = data && data.error ? data.error : {};
    throw new ApiError(res.status, err.code, err.message, data);
  }
  return data;
}

async function refreshSession() {
  if (refreshInFlight) return refreshInFlight;

  refreshInFlight = (async () => {
    const s = await getSession();
    if (!s || !s.refresh_token) throw new ApiError(401, 'no_refresh_token', 'Session expired.');
    const res = await rawFetch('/auth/refresh', {
      method: 'POST',
      body: { refresh_token: s.refresh_token },
    });
    const bundle = await parse(res); // throws ApiError on reauth_required / invalid_token
    await setSession(bundle);
    return bundle;
  })();

  try {
    return await refreshInFlight;
  } finally {
    refreshInFlight = null;
  }
}

/**
 * Authenticated call. Set `auth:false` for the login endpoint.
 * On a 401 it refreshes once and retries; if that fails the session is cleared
 * and the ApiError propagates (the router sends the user back to login).
 */
export async function apiFetch(path, { method = 'GET', body, auth = true, headers } = {}) {
  let token = null;
  if (auth) {
    const s = await getSession();
    token = s && s.access_token;
  }

  let res = await rawFetch(path, { method, body, token, headers });

  if (res.status === 401 && auth) {
    try {
      const bundle = await refreshSession();
      res = await rawFetch(path, { method, body, token: bundle.access_token, headers });
    } catch (err) {
      if (err instanceof OfflineError) throw err;
      await clearSession();
      throw err instanceof ApiError ? err : new ApiError(401, 'session_expired', 'Please log in again.');
    }
  }

  return parse(res);
}

// --- endpoint helpers --------------------------------------------------

export function login(email, password) {
  return apiFetch('/auth/login', { method: 'POST', auth: false, body: { email, password } });
}

export function me() {
  return apiFetch('/me');
}

export function setPin(currentPassword, pin) {
  return apiFetch('/me/pin', { method: 'POST', body: { current_password: currentPassword, pin } });
}

export function clearPin() {
  return apiFetch('/me/pin', { method: 'DELETE' });
}

export function getAssigned() {
  return apiFetch('/inspections/assigned');
}

export function syncInspections(payload) {
  return apiFetch('/inspections/sync', { method: 'POST', body: payload });
}

export function uploadAttachment(clientUuid, blob, { checksum, originalName }) {
  return apiFetch(`/inspections/attachments/${clientUuid}`, {
    method: 'POST',
    body: blob,
    headers: {
      'X-Checksum-Sha256': checksum,
      'X-Original-Name': originalName || 'photo.jpg',
      'Content-Type': blob.type || 'application/octet-stream',
    },
  });
}
