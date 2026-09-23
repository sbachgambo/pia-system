// Offline PIN verification against the Argon2id `pin_hash` handed out by the
// server (brief §6, assumption A7).
//
// `vendor/argon2/argon2-bundled.min.js` is loaded as a classic <script> in
// index.html and exposes `window.argon2`. It fetches argon2.wasm from its own
// directory on first use; both files are precached by the service worker, so
// this works with no network.
//
// If the WASM can't load (blocked, ancient browser), verification is reported
// as unavailable and the app falls back to requiring an online login.

let readyProbe = null;

function argon() {
  return typeof window !== 'undefined' ? window.argon2 : undefined;
}

export function isArgonPresent() {
  return !!argon() && typeof argon().verify === 'function';
}

/**
 * @returns {Promise<{ok:true}|{ok:false}|{unavailable:true, reason?:string}>}
 */
export async function verifyPin(pin, encodedHash) {
  const a = argon();
  if (!a || typeof a.verify !== 'function') {
    return { unavailable: true, reason: 'argon2 not loaded' };
  }
  if (!encodedHash) {
    return { unavailable: true, reason: 'no pin_hash cached' };
  }

  try {
    // `type` is left to argon2-browser to infer from the "$argon2id$" prefix.
    await a.verify({ pass: String(pin), encoded: encodedHash });
    return { ok: true };
  } catch (err) {
    // argon2-browser rejects with a numeric `code`; a mismatch is the normal
    // "wrong PIN" path. A thrown loader/runtime error means it's unusable.
    if (err && typeof err.code === 'number') {
      return { ok: false };
    }
    return { unavailable: true, reason: (err && err.message) || 'verify failed' };
  }
}

/** Warm the WASM module so the first real unlock isn't slow. Best-effort. */
export function warmUp() {
  if (readyProbe) return readyProbe;
  const a = argon();
  if (!a || typeof a.hash !== 'function') {
    readyProbe = Promise.resolve(false);
    return readyProbe;
  }
  readyProbe = a
    .hash({ pass: 'warmup', salt: 'warmup-salt', time: 1, mem: 1024, parallelism: 1, type: a.ArgonType.Argon2id })
    .then(() => true)
    .catch(() => false);
  return readyProbe;
}
