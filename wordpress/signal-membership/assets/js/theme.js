(function () {
  'use strict';
  var KEY = 'sig-theme';

  function current() {
    try {
      var t = localStorage.getItem(KEY);
      if (t === 'light' || t === 'dark') {
        return t;
      }
    } catch (err) {}
    return 'dark';
  }

  function apply(theme) {
    document.documentElement.setAttribute('data-theme', theme);
    try {
      localStorage.setItem(KEY, theme);
    } catch (err) {}
    var btn = document.getElementById('sig-theme-toggle');
    if (btn) {
      var isLight = theme === 'light';
      btn.setAttribute('aria-pressed', isLight ? 'true' : 'false');
      btn.title = isLight ? 'Switch to dark' : 'Switch to light';
      var lab = btn.querySelector('.sig-theme-label');
      if (lab) {
        lab.textContent = isLight ? 'Dark' : 'Light';
      }
    }
    document.dispatchEvent(new CustomEvent('sig-theme', { detail: theme }));
  }

  apply(current());

  document.addEventListener('DOMContentLoaded', function () {
    apply(current());
    var btn = document.getElementById('sig-theme-toggle');
    if (!btn) {
      return;
    }
    btn.addEventListener('click', function () {
      apply(current() === 'light' ? 'dark' : 'light');
    });
  });
})();
