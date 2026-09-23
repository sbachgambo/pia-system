// The sync engine: pull the assigned working set down, push queued local work
// up. Safe to call repeatedly — the server is idempotent on the inspection
// uuid (§3) and on the attachment client_uuid.

import { getAssigned, syncInspections, uploadAttachment, OfflineError } from './api.js';
import {
  mergeAssigned,
  listInspections,
  putInspection,
  getInspection,
  attachmentsFor,
  pendingAttachments,
  markAttachmentUploaded,
  putReference,
  getReference,
} from './store.js';

let running = false;
const listeners = new Set();

export function onSyncChange(fn) {
  listeners.add(fn);
  return () => listeners.delete(fn);
}
function emit(state) {
  for (const fn of listeners) {
    try { fn(state); } catch { /* ignore */ }
  }
}

export async function lastSyncAt() {
  const row = await getReference('last_sync');
  return row ? row.payload.at : null;
}

export function isOnline() {
  return navigator.onLine !== false;
}

/** Build the /sync payload from every locally-queued inspection. */
async function buildBatch() {
  const all = await listInspections();
  const queued = all.filter((r) => r._state === 'queued');
  const inspections = [];

  for (const rec of queued) {
    const atts = await attachmentsFor(rec.uuid);
    inspections.push({
      uuid: rec.uuid,
      status: 'synced',
      started_at: rec.started_at || undefined,
      findings: (rec.findings || [])
        .filter((f) => (f.checklist_item || '').trim() !== '')
        .map((f) => ({
          checklist_item: f.checklist_item.trim(),
          expected_value: f.expected_value || undefined,
          observed_value: f.observed_value || undefined,
          result: f.result || 'pass',
          notes: f.notes || undefined,
        })),
      attachments: atts.map((a) => ({
        client_uuid: a.id,
        file_type: a.file_type || 'photo',
        captured_at: a.captured_at,
        checksum_sha256: a.checksum,
        original_name: a.original_name || undefined,
      })),
    });
  }

  return { queued, payload: { inspections } };
}

async function push() {
  const { queued, payload } = await buildBatch();
  if (payload.inspections.length === 0) return { pushed: 0, rejected: 0 };

  const res = await syncInspections(payload);
  const byUuid = new Map(res.results.map((r) => [r.uuid, r]));
  let pushed = 0;
  let rejected = 0;

  for (const rec of queued) {
    const r = byUuid.get(rec.uuid);
    const fresh = (await getInspection(rec.uuid)) || rec;
    if (r && r.status === 'accepted') {
      fresh._state = 'clean';
      fresh._sync_detail = null;
      fresh.status = r.inspection_status || 'synced';
      pushed += 1;
    } else {
      fresh._state = 'error';
      fresh._sync_detail = r ? r.reason + (r.detail ? `: ${r.detail}` : '') : 'no response';
      rejected += 1;
    }
    await putInspection(fresh);
  }

  // Upload any blobs the server is still waiting on.
  const pend = await pendingAttachments();
  for (const a of pend) {
    const owner = await getInspection(a.inspection_uuid);
    if (!owner || owner._state === 'error') continue; // inspection not accepted yet
    try {
      await uploadAttachment(a.id, a.blob, { checksum: a.checksum, originalName: a.original_name });
      await markAttachmentUploaded(a.id);
    } catch (err) {
      if (err instanceof OfflineError) throw err;
      // Leave it pending; a later sync retries.
    }
  }

  return { pushed, rejected };
}

async function pull() {
  const data = await getAssigned();
  await putReference('assigned', data);
  await mergeAssigned(data.inspections || []);
  return (data.inspections || []).length;
}

/**
 * Full sync: push local work, then pull the fresh working set.
 * Throws OfflineError when there's no connection.
 */
export async function syncNow({ silent = false } = {}) {
  if (running) return { skipped: true };
  if (!isOnline()) throw new OfflineError();

  running = true;
  if (!silent) emit({ phase: 'syncing' });

  try {
    const pushRes = await push();
    const assignedCount = await pull();
    await putReference('last_sync', { at: new Date().toISOString() });
    const state = { phase: 'idle', ...pushRes, assignedCount, at: new Date().toISOString() };
    emit(state);
    return state;
  } catch (err) {
    emit({ phase: 'error', error: err instanceof OfflineError ? 'offline' : (err.message || 'sync failed') });
    throw err;
  } finally {
    running = false;
  }
}
