(function () {
  var THEME_KEY = 'killi-admin-theme';
  var ICON_SUN = '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="4.5"/><path d="M12 2v3M12 19v3M4.2 4.2l2.1 2.1M17.7 17.7l2.1 2.1M2 12h3M19 12h3M4.2 19.8l2.1-2.1M17.7 6.3l2.1-2.1"/></svg>';
  var ICON_MOON = '<svg viewBox="0 0 24 24"><path d="M20 14.5A8.5 8.5 0 1 1 9.5 4a7 7 0 0 0 10.5 10.5Z"/></svg>';

  function systemPrefersDark() {
    return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
  }

  function isDarkActive() {
    var explicit = document.documentElement.getAttribute('data-theme');
    if (explicit === 'dark') return true;
    if (explicit === 'light') return false;
    return systemPrefersDark();
  }

  var toggle = document.getElementById('admin-theme-toggle');

  function applyTheme(theme) {
    if (theme === 'dark' || theme === 'light') {
      document.documentElement.setAttribute('data-theme', theme);
    } else {
      document.documentElement.removeAttribute('data-theme');
    }
    if (toggle) toggle.innerHTML = isDarkActive() ? ICON_SUN : ICON_MOON;
  }

  applyTheme(localStorage.getItem(THEME_KEY));

  if (toggle) {
    toggle.addEventListener('click', function () {
      var next = isDarkActive() ? 'light' : 'dark';
      localStorage.setItem(THEME_KEY, next);
      applyTheme(next);
    });
  }

  var moreBtn = document.getElementById('admin-more-btn');
  var moreSheet = document.getElementById('admin-more-sheet');
  if (moreBtn && moreSheet) {
    moreBtn.addEventListener('click', function () { moreSheet.hidden = false; });
    moreSheet.addEventListener('click', function (e) {
      if (e.target === moreSheet) moreSheet.hidden = true;
    });
    var closeBtn = document.getElementById('admin-more-close');
    if (closeBtn) closeBtn.addEventListener('click', function () { moreSheet.hidden = true; });
  }
})();
