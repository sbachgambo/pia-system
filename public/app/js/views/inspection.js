import { h, render, toast, confirmDialog } from '../ui.js';
import { getInspection, putInspection, addAttachment, attachmentsFor, deleteAttachment, normaliseFinding } from '../store.js';
import { uuidv4, sha256Hex, escapeHtml, fmtDateTime } from '../util.js';
import { navigate } from '../app.js';

const RESULTS = ['pass', 'fail', 'flag'];

export async function showInspection(uuid) {
  const rec = await getInspection(uuid);
  if (!rec) {
    toast('That inspection is no longer on this device.', 'err');
    navigate('/');
    return;
  }

  const locked = rec.status === 'finalized';
  const c = rec.consignment || {};

  // Working copy of findings (seed one blank row if empty and unlocked).
  let findings = (rec.findings || []).map(normaliseFinding);
  if (!findings.length && !locked) findings = [blankFinding()];

  const findingsBody = h('tbody');
  const photoStrip = h('div', { class: 'photo-strip' });

  function blankFinding() {
    return { checklist_item: '', expected_value: '', observed_value: '', result: 'pass', notes: '' };
  }

  function renderFindings() {
    findingsBody.replaceChildren(...findings.map((f, i) => findingRow(f, i)));
  }

  function findingRow(f, i) {
    // oninput keeps the working array current; onchange (blur / select) also
    // persists a draft so field edits survive navigating away.
    const bind = (key) => (e) => { f[key] = e.target.value; };
    const persist = () => { markDirty(); };
    return h(
      'tr',
      {},
      h('td', {}, h('input', { value: f.checklist_item, placeholder: 'Item checked', disabled: locked, oninput: bind('checklist_item'), onchange: persist })),
      h('td', {}, h('input', { value: f.expected_value, placeholder: 'Expected', disabled: locked, oninput: bind('expected_value'), onchange: persist })),
      h('td', {}, h('input', { value: f.observed_value, placeholder: 'Observed', disabled: locked, oninput: bind('observed_value'), onchange: persist })),
      h('td', {}, h('select', { disabled: locked, onchange: (e) => { bind('result')(e); persist(); } },
        ...RESULTS.map((r) => h('option', { value: r, selected: f.result === r }, r)))),
      h('td', {}, h('input', { value: f.notes, placeholder: 'Notes', disabled: locked, oninput: bind('notes'), onchange: persist })),
      h('td', {}, locked ? null : h('button', {
        class: 'btn-icon', title: 'Remove', type: 'button',
        onclick: () => { findings.splice(i, 1); if (!findings.length) findings.push(blankFinding()); renderFindings(); },
      }, '✕')),
    );
  }

  async function renderPhotos() {
    const atts = await attachmentsFor(uuid);
    photoStrip.replaceChildren(
      ...atts.map((a) => {
        const url = URL.createObjectURL(a.blob);
        const img = h('img', { src: url, alt: a.original_name || 'photo', onload: () => URL.revokeObjectURL(url) });
        return h('div', { class: 'thumb' },
          img,
          h('span', { class: `thumb-tag ${a._state === 'uploaded' ? 'ok' : ''}` }, a._state === 'uploaded' ? 'uploaded' : 'pending'),
          locked ? null : h('button', {
            class: 'btn-icon thumb-del', type: 'button', title: 'Delete',
            onclick: async () => {
              if (await confirmDialog('Delete this photo?')) { await deleteAttachment(a.id); renderPhotos(); }
            },
          }, '✕'));
      }),
      atts.length ? null : h('p', { class: 'muted small' }, 'No photos yet.'),
    );
  }

  const fileInput = h('input', {
    type: 'file', accept: 'image/*', capture: 'environment', multiple: true, hidden: true,
    onchange: async (e) => {
      const files = [...e.target.files];
      e.target.value = '';
      for (const file of files) {
        if (file.size > 10 * 1024 * 1024) { toast(`${file.name} is over 10 MB — skipped.`, 'err'); continue; }
        try {
          const checksum = await sha256Hex(file);
          await addAttachment({
            id: uuidv4(),
            inspection_uuid: uuid,
            blob: file,
            mime: file.type || 'image/jpeg',
            original_name: file.name || 'photo.jpg',
            file_type: 'photo',
            captured_at: new Date().toISOString(),
            checksum,
            _state: 'pending',
          });
        } catch (err) {
          toast('Could not read that photo.', 'err');
        }
      }
      await renderPhotos();
      await markDirty();
    },
  });

  async function collect() {
    rec.findings = findings
      .map((f) => ({ ...f, checklist_item: (f.checklist_item || '').trim() }))
      .filter((f) => f.checklist_item !== '');
    if (!rec.started_at) rec.started_at = new Date().toISOString();
  }

  async function markDirty() {
    if (locked) return;
    await collect();
    if (rec._state === 'clean' || !rec._state) rec._state = 'dirty';
    await putInspection(rec);
  }

  async function saveDraft() {
    await markDirty();
    toast('Draft saved on this device.', 'ok');
  }

  async function markReady() {
    if (locked) return;
    await collect();
    if (!rec.findings.length) { toast('Add at least one finding before syncing.', 'err'); return; }
    rec._state = 'queued';
    rec._sync_detail = null;
    await putInspection(rec);
    toast('Marked ready. It will upload on the next sync.', 'ok');
    navigate('/');
  }

  renderFindings();
  await renderPhotos();

  render(h('div', { class: 'screen' },
    h('header', { class: 'screen-head' },
      h('button', { class: 'btn-link', onclick: () => navigate('/') }, '‹ Back'),
      h('h1', {}, c.client_name || 'Inspection')),

    rec.status === 'finalized' ? h('div', { class: 'banner banner-ok' }, 'Finalised by the office — locked, read-only.') : null,
    rec.status === 'rejected' ? h('div', { class: 'banner banner-warn' }, 'Sent back by the office. Make your corrections and mark it ready to sync again.') : null,
    rec._state === 'error' && rec._sync_detail ? h('div', { class: 'banner banner-err' }, `Last sync rejected: ${escapeHtml(rec._sync_detail)}`) : null,

    h('section', { class: 'card' },
      h('h2', {}, c.nxp_number ? 'NXP ' + c.nxp_number : 'NXP record'),
      dl([
        ['Product', `${c.product_category || ''}`],
        ['Description', c.product_description || '—'],
        ['HS code', c.hs_code || '—'],
        ['Quantity', c.quantity ? `${c.quantity} ${c.unit_of_measure || ''}` : '—'],
        ['Declared value', c.declared_value ? `${c.declared_value} ${c.currency || ''}` : '—'],
        ['Route', `${c.origin_country || '?'} → ${c.destination_country || '?'}`],
        ['Location', `${rec.location_type || ''}${rec.location_detail ? ' · ' + rec.location_detail : ''}`],
        ['Scheduled', fmtDateTime(rec.scheduled_at)],
      ])),

    h('section', { class: 'card' },
      h('h2', {}, 'Findings'),
      h('div', { class: 'table-scroll' },
        h('table', { class: 'findings' },
          h('thead', {}, h('tr', {}, ...['Item', 'Expected', 'Observed', 'Result', 'Notes', ''].map((t) => h('th', {}, t)))),
          findingsBody)),
      locked ? null : h('button', { class: 'btn-secondary', type: 'button', onclick: () => { findings.push(blankFinding()); renderFindings(); } }, '+ Add finding')),

    h('section', { class: 'card' },
      h('h2', {}, 'Photos'),
      photoStrip,
      locked ? null : h('button', { class: 'btn-secondary', type: 'button', onclick: () => fileInput.click() }, '＋ Add photo'),
      fileInput),

    locked ? null : h('div', { class: 'action-bar' },
      h('button', { class: 'btn-secondary', type: 'button', onclick: saveDraft }, 'Save draft'),
      h('button', { class: 'btn-primary', type: 'button', onclick: markReady }, 'Mark ready to sync')),
  ));
}

function dl(pairs) {
  return h('dl', { class: 'kv' }, ...pairs.flatMap(([k, v]) => [h('dt', {}, k), h('dd', {}, String(v))]));
}
