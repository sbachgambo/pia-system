import { h, render, toast, confirmDialog } from '../ui.js';
import { currentUser, offlineInfo, reauthDeadline, logout, refreshProfile } from '../auth.js';
import { setPin as apiSetPin, clearPin as apiClearPin, OfflineError } from '../api.js';
import { patchSessionOffline, listInspections } from '../store.js';
import { isArgonPresent } from '../argon.js';
import { syncNow } from '../sync.js';
import { fmtDateTime } from '../util.js';
import { navigate } from '../app.js';

export async function showSettings() {
  await refreshProfile(); // best-effort, keeps pin_set / deadline fresh when online
  const user = await currentUser();
  const info = (await offlineInfo()) || {};
  const deadline = await reauthDeadline();
  const argonOk = isArgonPresent();

  render(h('div', { class: 'screen' },
    h('header', { class: 'screen-head' },
      h('button', { class: 'btn-link', onclick: () => navigate('/') }, '‹ Back'),
      h('h1', {}, 'Settings')),

    h('section', { class: 'card' },
      h('h2', {}, 'Account'),
      h('dl', { class: 'kv' },
        h('dt', {}, 'Name'), h('dd', {}, user?.full_name || '—'),
        h('dt', {}, 'Email'), h('dd', {}, user?.email || '—'),
        h('dt', {}, 'Zone'), h('dd', {}, user?.zone || '—'),
        h('dt', {}, 'Online login needed by'), h('dd', {}, deadline ? fmtDateTime(deadline.toISOString()) : '—'))),

    pinCard(info, argonOk),

    h('section', { class: 'card' },
      h('h2', {}, 'Sync'),
      h('button', { class: 'btn-secondary', onclick: async (e) => {
        e.target.disabled = true;
        try { const r = await syncNow(); toast(`Done — ${r.pushed || 0} uploaded, ${r.rejected || 0} rejected.`, r.rejected ? 'warn' : 'ok'); }
        catch (err) { toast(err instanceof OfflineError ? 'Offline.' : 'Sync failed.', 'err'); }
        finally { e.target.disabled = false; }
      } }, 'Sync now')),

    h('section', { class: 'card' },
      h('h2', {}, 'Session'),
      h('button', { class: 'btn-danger', onclick: doLogout }, 'Log out')),
  ));
}

function pinCard(info, argonOk) {
  const current = h('input', { type: 'password', autocomplete: 'current-password', placeholder: 'Account password' });
  const p1 = h('input', { type: 'password', inputmode: 'numeric', pattern: '[0-9]*', placeholder: 'New PIN (4–8 digits)', maxlength: 8 });
  const p2 = h('input', { type: 'password', inputmode: 'numeric', pattern: '[0-9]*', placeholder: 'Repeat PIN', maxlength: 8 });

  const save = h('button', { class: 'btn-primary', onclick: async () => {
    if (p1.value !== p2.value) { toast('PINs do not match.', 'err'); return; }
    save.disabled = true;
    try {
      const res = await apiSetPin(current.value, p1.value);
      await patchSessionOffline(res.offline);
      toast('PIN saved. You can now unlock offline.', 'ok');
      showSettings();
    } catch (err) {
      if (err instanceof OfflineError) toast('You must be online to set a PIN.', 'err');
      else if (err.code === 'weak_pin') toast(err.message || 'Choose a less predictable PIN.', 'err');
      else if (err.status === 401) toast('Account password is incorrect.', 'err');
      else toast('Could not save PIN.', 'err');
      save.disabled = false;
    }
  } }, info.pin_set ? 'Replace PIN' : 'Set PIN');

  const children = [
    h('h2', {}, 'Offline PIN'),
    h('p', { class: 'muted small' }, 'Used to unlock the app when you have no connection. Verified on this device only.'),
  ];

  if (!argonOk) {
    children.push(h('div', { class: 'banner banner-warn' }, 'This browser can’t verify a PIN offline. You’ll need to log in online each time.'));
  }

  children.push(
    h('div', { class: 'chip ' + (info.pin_set ? 'chip-ok' : 'chip-warn') }, info.pin_set ? 'PIN is set' : 'No PIN set'),
    h('label', {}, 'Account password', current),
    h('label', {}, 'New PIN', p1),
    h('label', {}, 'Repeat PIN', p2),
    h('div', { class: 'action-bar' }, save,
      info.pin_set ? h('button', { class: 'btn-secondary', onclick: async () => {
        if (!(await confirmDialog('Remove the offline PIN?'))) return;
        try { const res = await apiClearPin(); await patchSessionOffline(res.offline); toast('PIN removed.', 'ok'); showSettings(); }
        catch (err) { toast(err instanceof OfflineError ? 'You must be online to remove the PIN.' : 'Could not remove PIN.', 'err'); }
      } }, 'Remove PIN') : null),
  );

  return h('section', { class: 'card' }, ...children);
}

async function doLogout() {
  const rows = await listInspections();
  const unsynced = rows.filter((r) => r._state && r._state !== 'clean').length;
  const msg = unsynced
    ? `You have ${unsynced} inspection(s) not yet synced. Logging out will KEEP them on this device but you must log back in to sync. Continue?`
    : 'Log out of the field app?';
  if (!(await confirmDialog(msg))) return;
  await logout({ wipe: false });
  navigate('/login');
}
