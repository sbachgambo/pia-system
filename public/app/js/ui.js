// Tiny DOM helpers — no framework.

export function h(tag, props = {}, ...children) {
  const node = document.createElement(tag);
  for (const [k, v] of Object.entries(props || {})) {
    if (v == null || v === false) continue;
    if (k === 'class') node.className = v;
    else if (k === 'html') node.innerHTML = v;
    else if (k === 'dataset') Object.assign(node.dataset, v);
    else if (k.startsWith('on') && typeof v === 'function') node.addEventListener(k.slice(2), v);
    else if (k in node && k !== 'list') node[k] = v;
    else node.setAttribute(k, v);
  }
  for (const c of children.flat()) {
    if (c == null || c === false) continue;
    node.append(c.nodeType ? c : document.createTextNode(String(c)));
  }
  return node;
}

const root = () => document.getElementById('view');

export function render(node) {
  const r = root();
  r.replaceChildren(node);
  r.scrollTop = 0;
}

let toastTimer = null;
export function toast(message, kind = 'info') {
  let box = document.getElementById('toast');
  if (!box) {
    box = h('div', { id: 'toast' });
    document.body.append(box);
  }
  box.className = `toast toast-${kind}`;
  box.textContent = message;
  box.classList.add('show');
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => box.classList.remove('show'), 3200);
}

export function statusChip(state) {
  const map = {
    clean: ['Synced', 'chip-ok'],
    dirty: ['Draft', 'chip-warn'],
    queued: ['Ready to sync', 'chip-info'],
    error: ['Sync failed', 'chip-err'],
  };
  const [label, cls] = map[state] || ['—', 'chip'];
  return h('span', { class: `chip ${cls}` }, label);
}

export function setConnDot(online) {
  const dot = document.getElementById('conn-dot');
  if (dot) {
    dot.className = `dot ${online ? 'dot-online' : 'dot-offline'}`;
    dot.title = online ? 'Online' : 'Offline';
  }
}

export function confirmDialog(message) {
  return Promise.resolve(window.confirm(message));
}
