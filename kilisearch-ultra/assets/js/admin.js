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

  // Mobile record list: swipe (or drag) a card left to reveal Edit/Delete.
  // Pointer Events cover touch/mouse/pen in one code path.
  function initSwipeCards() {
    var openFront = null;

    function closeOpen() {
      if (openFront) {
        openFront.style.transform = 'translateX(0)';
        openFront = null;
      }
    }

    Array.prototype.forEach.call(document.querySelectorAll('.admin-swipe-card'), function (card) {
      var front = card.querySelector('.admin-swipe-front');
      var actions = card.querySelector('.admin-swipe-actions');
      if (!front || !actions) return;
      var revealWidth = 0;
      var startX = 0;
      var startTranslate = 0;
      var dragging = false;

      front.addEventListener('pointerdown', function (e) {
        revealWidth = actions.offsetWidth;
        if (openFront && openFront !== front) closeOpen();
        startX = e.clientX;
        var current = front.style.transform.match(/-?\d+(\.\d+)?/);
        startTranslate = current ? parseFloat(current[0]) : 0;
        dragging = true;
        front.style.transition = 'none';
        front.setPointerCapture && front.setPointerCapture(e.pointerId);
      });

      front.addEventListener('pointermove', function (e) {
        if (!dragging) return;
        var delta = e.clientX - startX;
        var next = Math.max(-revealWidth, Math.min(0, startTranslate + delta));
        front.style.transform = 'translateX(' + next + 'px)';
      });

      function endDrag(e) {
        if (!dragging) return;
        dragging = false;
        front.style.transition = 'transform 0.2s ease';
        var current = front.style.transform.match(/-?\d+(\.\d+)?/);
        var value = current ? parseFloat(current[0]) : 0;
        if (value < -revealWidth / 2) {
          front.style.transform = 'translateX(-' + revealWidth + 'px)';
          openFront = front;
        } else {
          front.style.transform = 'translateX(0)';
          if (openFront === front) openFront = null;
        }
      }

      front.addEventListener('pointerup', endDrag);
      front.addEventListener('pointercancel', endDrag);
    });

    document.addEventListener('pointerdown', function (e) {
      if (openFront && !openFront.contains(e.target)) closeOpen();
    });
  }

  if (document.querySelector('.admin-swipe-card')) initSwipeCards();
})();
