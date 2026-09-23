// Resolves the effective theme (saved choice, else OS preference) and sets
// it on <html> BEFORE first paint, so there is no flash of the wrong theme.
// Loaded as a plain <script src> (not deferred) at the top of <head> — CSP is
// script-src 'self' with no 'unsafe-inline', so this can't be an inline
// script; a non-inline one placed before the stylesheet has the same effect.
(function () {
  'use strict';
  try {
    var saved = localStorage.getItem('pia-theme');
    var theme = (saved === 'dark' || saved === 'light')
      ? saved
      : (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
    document.documentElement.setAttribute('data-theme', theme);
  } catch (e) {
    // Storage blocked (private mode, etc.) — CSS media-query fallback still applies.
  }
})();
