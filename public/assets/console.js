// Tiny unobtrusive behaviours for the office console —
// kept out of the HTML (no inline event handlers / no inline <script>) so both
// can run under a strict Content-Security-Policy (script-src 'self', no
// 'unsafe-inline' — Phase 9 hardening).
(function () {
  'use strict';

  // data-confirm on a <form>: ask before submitting.
  document.addEventListener('submit', function (event) {
    var form = event.target;
    if (!(form instanceof HTMLFormElement)) return;

    var message = form.getAttribute('data-confirm');
    if (message && !window.confirm(message)) {
      event.preventDefault();
    }
  });

  // Manual light/dark toggle. theme-init.js already resolved the starting
  // value on <html data-theme>; this just flips + persists it.
  var themeToggle = document.getElementById('themeToggle');
  if (themeToggle) {
    themeToggle.addEventListener('click', function () {
      var current = document.documentElement.getAttribute('data-theme');
      var next = current === 'dark' ? 'light' : 'dark';
      document.documentElement.setAttribute('data-theme', next);
      try { localStorage.setItem('pia-theme', next); } catch (e) {}
    });
  }

  // Off-canvas sidebar toggle (console, mobile/tablet only — hidden by CSS on
  // desktop).
  var sidebar = document.getElementById('sidebar');
  var toggle = document.getElementById('sidebarToggle');
  var backdrop = document.getElementById('sidebarBackdrop');
  if (!sidebar || !toggle || !backdrop) return;

  function closeSidebar() {
    sidebar.classList.remove('open');
    backdrop.classList.remove('show');
    toggle.setAttribute('aria-expanded', 'false');
  }
  function openSidebar() {
    sidebar.classList.add('open');
    backdrop.classList.add('show');
    toggle.setAttribute('aria-expanded', 'true');
  }

  toggle.addEventListener('click', function () {
    if (sidebar.classList.contains('open')) closeSidebar(); else openSidebar();
  });
  backdrop.addEventListener('click', closeSidebar);
  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') closeSidebar();
  });
})();

// Dashboard income card: switching the period swaps the card in place (the
// server renders the same fragment), so the rest of the dashboard stays put.
// Every control is also a plain link / GET form, so this is only a shortcut.
(function () {
  'use strict';

  function load(query) {
    var card = document.getElementById('incomeCard');
    if (!card || !window.fetch) {
      window.location.href = '/console?' + query + '#incomeCard';
      return;
    }
    card.classList.add('is-loading');
    fetch('/console/income/card?' + query, { credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } })
      .then(function (r) {
        if (!r.ok || r.redirected) throw new Error('income card ' + r.status);
        return r.text();
      })
      .then(function (html) {
        var current = document.getElementById('incomeCard');
        if (current) current.outerHTML = html;
        try { history.replaceState(null, '', '/console?' + query + '#incomeCard'); } catch (e) {}
      })
      .catch(function () { window.location.href = '/console?' + query + '#incomeCard'; });
  }

  document.addEventListener('click', function (event) {
    var link = event.target instanceof Element ? event.target.closest('[data-income-period]') : null;
    if (!link || event.metaKey || event.ctrlKey || event.shiftKey) return;
    event.preventDefault();
    load('income=' + encodeURIComponent(link.getAttribute('data-income-period')));
  });

  document.addEventListener('submit', function (event) {
    var form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-income-form')) return;
    event.preventDefault();
    var params = [];
    Array.prototype.forEach.call(form.elements, function (el) {
      if (el.name) params.push(encodeURIComponent(el.name) + '=' + encodeURIComponent(el.value));
    });
    load(params.join('&'));
  });
})();

// Board of Directors (read-only): lock every form that would change something,
// so the page reads as a record rather than an editor. The server refuses
// those requests anyway (BoardReadOnlyMiddleware); this is only presentation.
(function () {
  'use strict';
  if (!document.body || !document.body.hasAttribute('data-read-only')) return;

  var forms = document.querySelectorAll('form');
  Array.prototype.forEach.call(forms, function (form) {
    var method = (form.getAttribute('method') || 'get').toLowerCase();
    var action = form.getAttribute('action') || '';
    if (method !== 'post' || form.classList.contains('ro-ok') || action.indexOf('/console/account') === 0) return;
    form.classList.add('ro-locked');
    Array.prototype.forEach.call(form.elements, function (el) { el.disabled = true; });
  });
})();
