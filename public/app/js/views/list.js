import { h, render, toast, statusChip } from '../ui.js';
import { listInspections } from '../store.js';
import { syncNow, lastSyncAt, isOnline } from '../sync.js';
import { OfflineError } from '../api.js';
import { fmtRelative, fmtDateTime, escapeHtml } from '../util.js';
import { navigate } from '../app.js';

let autoPulled = false;

export async function showList() {
  const rows = (await listInspections()).sort(sortRows);
  const since = await lastSyncAt();

  const syncBtn = h('button', { class: 'btn-primary', onclick: () => runSync(syncBtn) }, 'Sync now');

  const bar = h(
    'div',
    { class: 'sync-bar' },
    h('div', { class: 'sync-meta' },
      h('span', { id: 'conn-dot', class: `dot ${isOnline() ? 'dot-online' : 'dot-offline'}` }),
      h('span', { class: 'muted' }, `Last sync: ${fmtRelative(since)}`)),
    syncBtn,
  );

  const list = rows.length
    ? h('ul', { class: 'card-list' }, ...rows.map(card))
    : h('div', { class: 'empty' }, isOnline()
        ? 'No inspections assigned. Tap "Sync now" to check again.'
        : 'Nothing downloaded yet. Connect to the internet and sync.');

  render(h('div', { class: 'screen' },
    h('header', { class: 'screen-head' },
      h('h1', {}, 'My inspections'),
      h('button', { class: 'btn-link', onclick: () => navigate('/settings') }, 'Settings')),
    bar,
    list));

  // Opportunistic first pull: only when we've never synced on this device and
  // are online. `since` is set after any sync, so this can't loop.
  if (isOnline() && rows.length === 0 && !since && !autoPulled) {
    autoPulled = true;
    runSync(syncBtn, { silent: true });
  }
}

function sortRows(a, b) {
  const rank = { error: 0, queued: 1, dirty: 2, clean: 3 };
  const ra = rank[a._state] ?? 3;
  const rb = rank[b._state] ?? 3;
  if (ra !== rb) return ra - rb;
  return String(a.scheduled_at || '').localeCompare(String(b.scheduled_at || ''));
}

function card(rec) {
  const c = rec.consignment || {};
  return h(
    'li',
    { class: 'card card-tap', onclick: () => navigate(`/inspection/${rec.uuid}`) },
    h('div', { class: 'card-row' },
      h('strong', {}, c.client_name || 'NXP record'),
      statusChip(rec._state || 'clean')),
    c.nxp_number ? h('div', { class: 'nxp-line' }, `NXP ${c.nxp_number}`) : null,
    h('div', { class: 'muted', html: `${escapeHtml(c.product_category || '')} · ${escapeHtml(c.direction || '')}` }),
    h('div', { class: 'muted small' },
      `${escapeHtml(rec.location_type || '')}${rec.location_detail ? ' · ' + escapeHtml(rec.location_detail) : ''}`),
    h('div', { class: 'muted small' }, `Scheduled ${fmtDateTime(rec.scheduled_at)}`),
    rec._state === 'error' && rec._sync_detail
      ? h('div', { class: 'err-text small' }, `Rejected: ${escapeHtml(rec._sync_detail)}`)
      : null,
  );
}

async function runSync(btn, opts = {}) {
  if (btn) { btn.disabled = true; btn.textContent = 'Syncing…'; }
  try {
    const res = await syncNow(opts);
    if (!opts.silent) {
      const bits = [];
      if (res.pushed) bits.push(`${res.pushed} uploaded`);
      if (res.rejected) bits.push(`${res.rejected} rejected`);
      toast(bits.length ? bits.join(', ') : 'Up to date', res.rejected ? 'warn' : 'ok');
    }
  } catch (err) {
    if (!opts.silent) {
      toast(err instanceof OfflineError ? 'Still offline — will sync when you reconnect.' : 'Sync failed.', 'err');
    }
  } finally {
    await showList();
  }
}
