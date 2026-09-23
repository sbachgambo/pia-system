// Typed helpers over the raw IndexedDB stores (brief §6).
//
// Local record states used by the sync engine:
//   clean   — matches the server, nothing to push
//   dirty   — edited locally, not yet marked ready
//   queued  — inspector marked it ready; next sync will push it
//   error   — last push was rejected; see _sync_detail

import { idb, STORES } from './idb.js';

// --- auth_cache: a single row keyed 'session' -------------------------------

export async function getSession() {
  return (await idb.get(STORES.auth, 'session')) || null;
}

export async function setSession(bundle) {
  const prev = (await getSession()) || {};
  const row = {
    key: 'session',
    access_token: bundle.access_token ?? prev.access_token ?? null,
    access_token_expires_at: bundle.access_token_expires_at ?? prev.access_token_expires_at ?? null,
    refresh_token: bundle.refresh_token ?? prev.refresh_token ?? null,
    refresh_token_expires_at: bundle.refresh_token_expires_at ?? prev.refresh_token_expires_at ?? null,
    user: bundle.user ?? prev.user ?? null,
    offline: bundle.offline ?? prev.offline ?? null,
    cached_at: new Date().toISOString(),
  };
  await idb.put(STORES.auth, row);
  return row;
}

export async function patchSessionOffline(offline) {
  const prev = (await getSession()) || { key: 'session' };
  prev.offline = offline;
  prev.cached_at = new Date().toISOString();
  await idb.put(STORES.auth, prev);
  return prev;
}

export async function clearSession() {
  await idb.delete(STORES.auth, 'session');
}

// --- reference_cache ------------------------------------------------------

export async function putReference(key, payload) {
  await idb.put(STORES.reference, { key, payload, fetched_at: new Date().toISOString() });
}

export async function getReference(key) {
  return (await idb.get(STORES.reference, key)) || null;
}

// --- inspections_queue -------------------------------------------------

export async function listInspections() {
  return (await idb.getAll(STORES.inspections)) || [];
}

export async function getInspection(uuid) {
  return (await idb.get(STORES.inspections, uuid)) || null;
}

export async function putInspection(record) {
  await idb.put(STORES.inspections, record);
  return record;
}

/**
 * Merge the server's /assigned rows into the local queue without trampling
 * unsynced local edits. A locally dirty/queued/error record is left alone;
 * everything else is refreshed from the server copy.
 */
export async function mergeAssigned(serverInspections) {
  const locals = await listInspections();
  const byUuid = new Map(locals.map((r) => [r.uuid, r]));

  for (const remote of serverInspections) {
    const local = byUuid.get(remote.uuid);
    if (local && local._state && local._state !== 'clean') {
      local._server = remote; // keep latest server view for reference
      await putInspection(local);
      continue;
    }
    await putInspection({
      uuid: remote.uuid,
      _state: 'clean',
      _sync_detail: null,
      status: remote.status,
      location_type: remote.location_type,
      location_detail: remote.location_detail,
      scheduled_at: remote.scheduled_at,
      started_at: remote.started_at,
      consignment: remote.consignment,
      checklist: remote.checklist,
      findings: (remote.findings || []).map(normaliseFinding),
      attachments: remote.attachments || [],
      _server: remote,
    });
    byUuid.delete(remote.uuid);
  }

  // Drop clean local rows the server no longer assigns (finalized elsewhere,
  // reassigned). Keep anything with unsynced work.
  for (const stale of byUuid.values()) {
    if (!stale._state || stale._state === 'clean') {
      await idb.delete(STORES.inspections, stale.uuid);
    }
  }
}

export function normaliseFinding(f) {
  return {
    checklist_item: f.checklist_item || '',
    expected_value: f.expected_value ?? '',
    observed_value: f.observed_value ?? '',
    result: f.result || 'pass',
    notes: f.notes ?? '',
  };
}

// --- attachments_queue ------------------------------------------------

export async function addAttachment(record) {
  await idb.put(STORES.attachments, record);
  return record;
}

export async function attachmentsFor(inspectionUuid) {
  return (await idb.getAllByIndex(STORES.attachments, 'by_inspection', inspectionUuid)) || [];
}

export async function pendingAttachments() {
  const all = (await idb.getAll(STORES.attachments)) || [];
  return all.filter((a) => a._state !== 'uploaded');
}

export async function markAttachmentUploaded(id) {
  const row = await idb.get(STORES.attachments, id);
  if (row) {
    row._state = 'uploaded';
    await idb.put(STORES.attachments, row);
  }
}

export async function deleteAttachment(id) {
  await idb.delete(STORES.attachments, id);
}
