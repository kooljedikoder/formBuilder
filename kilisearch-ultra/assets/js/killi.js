(function () {
  'use strict';

  var API_BASE = '../api/';
  var chat = document.getElementById('killi-chat');
  var form = document.getElementById('killi-searchbar');
  var input = document.getElementById('killi-input');
  var suggestionsBox = document.getElementById('killi-suggestions');
  var chips = document.getElementById('killi-chips');
  var themeToggle = document.getElementById('killi-theme-toggle');
  var attachBtn = document.getElementById('killi-attach');
  var attachInput = document.getElementById('killi-attach-input');
  var attachCameraPhoto = document.getElementById('killi-attach-camera-photo');
  var attachCameraVideo = document.getElementById('killi-attach-camera-video');
  var attachSheet = document.getElementById('killi-attach-sheet');
  var micBtn = document.getElementById('killi-mic');
  var branding = window.KILLI_BRANDING || {};
  var lastResults = [];
  var suggestTimer = null;
  var lastUserTick = null;
  var rateBtn = document.getElementById('killi-rate');
  var lastUserMessage = '';
  var sessionTurns = [];

  function el(tag, className, html) {
    var node = document.createElement(tag);
    if (className) node.className = className;
    if (html !== undefined) node.innerHTML = html;
    return node;
  }

  function scrollToBottom() {
    chat.scrollTop = chat.scrollHeight;
  }

  function formatTimestamp(date) {
    return date.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
  }

  /**
   * withReaction marks this bubble as a "final answer" AI reply (not a
   * breadcrumb or mid-form question) — those are what get paired up for
   * the end-of-conversation rating panel. There's no per-reply feedback
   * control anymore: rating happens once, at the end of the session, via
   * the star button this reveals — asking on every single reply was too
   * much friction for how short most conversations are.
   */
  function addBubble(role, text, withReaction) {
    var bubble = el('div', 'killi-bubble ' + role, escapeHtml(text));
    var meta = el('div', 'killi-bubble-meta');
    meta.appendChild(el('span', 'killi-timestamp', formatTimestamp(new Date())));
    if (role === 'user') {
      var tick = el('span', 'killi-tick', '&#10003;');
      meta.appendChild(tick);
      lastUserTick = tick;
      lastUserMessage = text;
    }
    bubble.appendChild(meta);
    chat.appendChild(bubble);
    if (role === 'ai' && withReaction) {
      sessionTurns.push({ query: lastUserMessage, reply: text });
      if (rateBtn && rateBtn.hidden) rateBtn.hidden = false;
    }
    scrollToBottom();
    return bubble;
  }

  /** Upgrades the most recent user message's tick from "sent" to "delivered" once a reply arrives. */
  function markDelivered() {
    if (lastUserTick) {
      lastUserTick.innerHTML = '&#10003;&#10003;';
      lastUserTick.classList.add('delivered');
      lastUserTick = null;
    }
  }

  function showTyping() {
    hideTyping();
    var bubble = el('div', 'killi-bubble ai killi-typing', '<span></span><span></span><span></span>');
    bubble.id = 'killi-typing-bubble';
    chat.appendChild(bubble);
    scrollToBottom();
  }

  function hideTyping() {
    var existing = document.getElementById('killi-typing-bubble');
    if (existing) existing.remove();
  }

  function addAttachmentBubble(file) {
    var bubble = el('div', 'killi-bubble user killi-attachment');
    if (file.mime && file.mime.indexOf('image/') === 0) {
      var img = document.createElement('img');
      img.src = file.url;
      img.alt = file.filename || 'Attachment';
      img.className = 'killi-attachment-img';
      bubble.appendChild(img);
    } else if (file.mime && file.mime.indexOf('video/') === 0) {
      var video = document.createElement('video');
      video.src = file.url;
      video.controls = true;
      video.className = 'killi-attachment-video';
      bubble.appendChild(video);
    } else {
      var link = el('a', 'killi-attachment-file', '&#128196; ' + escapeHtml(file.filename || 'Attachment'));
      link.href = file.url;
      link.target = '_blank';
      link.rel = 'noopener';
      bubble.appendChild(link);
    }
    var meta = el('div', 'killi-bubble-meta');
    meta.appendChild(el('span', 'killi-timestamp', formatTimestamp(new Date())));
    var tick = el('span', 'killi-tick', '&#10003;');
    meta.appendChild(tick);
    bubble.appendChild(meta);
    lastUserTick = tick;
    chat.appendChild(bubble);
    scrollToBottom();
  }

  /** Sends an already-uploaded file to the bot as its own turn — acknowledged by chat.php ahead of any form/intent handling. */
  function sendAttachment(file) {
    addAttachmentBubble(file);
    showTyping();
    return fetch(API_BASE + 'chat.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ message: '(attachment)', attachment: file }),
    })
      .then(function (res) { return res.json(); })
      .then(function (payload) {
        hideTyping();
        if (!payload.success && payload.error && payload.error.code === 'AUTH_REQUIRED') {
          showUnlockPrompt(function () { sendAttachment(file); });
          return;
        }
        markDelivered();
        if (payload.success) {
          addBubble('ai', payload.data.reply, true);
        } else {
          addBubble('ai', payload.error && payload.error.message ? payload.error.message : 'Something went wrong.');
        }
      })
      .catch(function () {
        hideTyping();
        addBubble('ai', 'I’m having trouble reaching the chat service. Please try again.');
      });
  }

  function addQuickReplies(replies) {
    var wrap = el('div', 'killi-quick-replies');
    replies.forEach(function (r) {
      var btn = el('button', 'killi-quick-reply', escapeHtml(r.label));
      btn.type = 'button';
      btn.addEventListener('click', r.onClick);
      wrap.appendChild(btn);
    });
    chat.appendChild(wrap);
    scrollToBottom();
    return wrap;
  }

  /**
   * The app-wide password can be turned on by an admin while a visitor is
   * mid-conversation (possibly mid-way through a multi-turn form). Rather
   * than bouncing to a full-page login — which would lose the rendered
   * chat transcript and any typed-but-unsent input — this shows an inline
   * unlock prompt in the chat itself and, once unlocked, re-runs the exact
   * same request via retryFn(). The server-side form/session state was
   * never lost either way (it lives in the PHP session, independent of
   * the app-password flag), so retrying picks up exactly where it left off.
   */
  function showUnlockPrompt(retryFn) {
    var wrap = el('div', 'killi-unlock-prompt');
    var text = el('p', 'killi-unlock-text', 'This app is password-protected. Enter the password to continue.');
    var row = el('div', 'killi-unlock-row');
    var pwInput = document.createElement('input');
    pwInput.type = 'password';
    pwInput.placeholder = 'Password';
    pwInput.className = 'killi-unlock-input';
    var btn = el('button', 'killi-unlock-btn', 'Unlock');
    btn.type = 'button';
    var errorEl = el('p', 'killi-unlock-error', '');
    errorEl.hidden = true;

    function submit() {
      btn.disabled = true;
      fetch(API_BASE + 'app_auth.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ password: pwInput.value }),
      })
        .then(function (res) { return res.json(); })
        .then(function (payload) {
          btn.disabled = false;
          if (payload.success) {
            wrap.remove();
            retryFn();
          } else {
            errorEl.textContent = (payload.error && payload.error.message) || 'Incorrect password.';
            errorEl.hidden = false;
            pwInput.value = '';
            pwInput.focus();
          }
        })
        .catch(function () {
          btn.disabled = false;
          errorEl.textContent = 'Could not reach the server. Please try again.';
          errorEl.hidden = false;
        });
    }

    btn.addEventListener('click', submit);
    pwInput.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') submit();
    });

    row.appendChild(pwInput);
    row.appendChild(btn);
    wrap.appendChild(text);
    wrap.appendChild(row);
    wrap.appendChild(errorEl);
    chat.appendChild(wrap);
    scrollToBottom();
    pwInput.focus();
  }

  function escapeHtml(str) {
    var div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
  }

  function starRating(rating) {
    if (!rating) return '';
    return '&#9733; ' + Number(rating).toFixed(1);
  }

  function waLink(phone) {
    if (!phone) return null;
    return 'https://wa.me/' + phone.replace(/[^0-9]/g, '');
  }

  function renderCard(record) {
    var card = el('div', 'killi-card');

    if (record.image) {
      var thumb = document.createElement('img');
      thumb.src = record.image;
      thumb.alt = record.title;
      thumb.className = 'killi-card-image';
      card.appendChild(thumb);
    }

    // Every listing gets a View button now, regardless of layout — even a
    // "simple" record with no rich fields still opens a (sparser) modal
    // with at least its title/rating/actions, via buildBusinessProfileBody's
    // graceful field-by-field degradation. The rating moves into the meta
    // line so it's still visible without crowding the title.
    var top = el('div', 'killi-card-top');
    top.appendChild(el('div', 'killi-card-title', escapeHtml(record.title) + (record.verified ? '<span class="killi-badge">Verified</span>' : '')));
    top.appendChild(buildViewButton(record));
    card.appendChild(top);

    var metaTextParts = [record.subcategory || record.category, record.location].filter(Boolean);
    if (record._distance_km !== undefined) metaTextParts.push(record._distance_km + ' km away');
    var metaHtml = escapeHtml(metaTextParts.join(' · '));
    if (record.rating) metaHtml = starRating(record.rating) + (metaHtml ? ' · ' + metaHtml : '');
    card.appendChild(el('div', 'killi-card-meta', metaHtml));

    if (record.source && record.source.name) {
      card.appendChild(el('div', 'killi-card-source', 'Source: ' + escapeHtml(record.source.name)));
    }

    var actions = el('div', 'killi-card-actions');
    if (record.phone) {
      var call = el('a', 'killi-action call', 'Call');
      call.href = 'tel:' + record.phone;
      actions.appendChild(call);
    }
    if (record.whatsapp) {
      var wa = el('a', 'killi-action whatsapp', 'WhatsApp');
      wa.href = waLink(record.whatsapp);
      wa.target = '_blank';
      wa.rel = 'noopener';
      actions.appendChild(wa);
    }
    if (record.website) {
      var site = el('a', 'killi-action website', 'Website');
      site.href = record.website;
      site.target = '_blank';
      site.rel = 'noopener';
      actions.appendChild(site);
    }
    card.appendChild(actions);

    return card;
  }

  // ---------------------------------------------------------------------
  // Result-detail layouts: "View" expands a record into a richer modal.
  // Which layout applies is decided server-side per data source
  // (DataSourceEngine::layoutFor(), stamped onto every record as
  // record._layout) — never picked or built by markup here. Every slot
  // below is optional: a record missing a field just skips that piece,
  // it never errors. See kilisearch-ultra/samples/*.sample.json for the
  // exact shape each layout expects.
  // ---------------------------------------------------------------------

  var ICONS = {
    eye: '<svg viewBox="0 0 24 24"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7Z"/><circle cx="12" cy="12" r="3"/></svg>',
    phone: '<svg viewBox="0 0 24 24"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92Z"/></svg>',
    pin: '<svg viewBox="0 0 24 24"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>',
    share: '<svg viewBox="0 0 24 24"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/></svg>',
    globe: '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10Z"/></svg>',
    star: '<svg viewBox="0 0 24 24"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87L18.18 21 12 17.27 5.82 21 7 14.14l-5-4.87 6.91-1.01L12 2Z"/></svg>',
    chevron: '<svg viewBox="0 0 24 24"><path d="M9 6l6 6-6 6"/></svg>',
    cart: '<svg viewBox="0 0 24 24"><circle cx="9" cy="21" r="1.4"/><circle cx="18" cy="21" r="1.4"/><path d="M2.5 3h2l2.6 12.6a2 2 0 0 0 2 1.6h7.7a2 2 0 0 0 2-1.6L21 8H6"/></svg>',
    home: '<svg viewBox="0 0 24 24"><path d="M3 11l9-8 9 8"/><path d="M5 10v10h14V10"/><path d="M9 20v-6h6v6"/></svg>',
    search: '<svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>',
    heart: '<svg viewBox="0 0 24 24"><path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.6l-1-1a5.5 5.5 0 0 0-7.8 7.8l1 1L12 21l7.8-7.8 1-1a5.5 5.5 0 0 0 0-7.6Z"/></svg>',
    user: '<svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-7 8-7s8 3 8 7"/></svg>',
  };

  /** A real 5-star row (filled up to the rounded rating), not one icon + a number — matches how every real business-profile card shows a rating. Returns markup, not a node, since callers build subline/tile HTML as strings. */
  function starRowHtml(rating) {
    var filled = Math.round(Number(rating) || 0);
    var html = '';
    for (var i = 1; i <= 5; i++) {
      html += '<span class="killi-star-row-icon' + (i <= filled ? ' filled' : '') + '">' + ICONS.star + '</span>';
    }
    return '<span class="killi-star-row">' + html + '</span>';
  }

  function buildViewButton(record) {
    var btn = el('button', 'killi-view-btn', ICONS.eye + ' View');
    btn.type = 'button';
    btn.setAttribute('aria-label', 'View full details for ' + record.title);
    btn.addEventListener('click', function () { openRecordModal(record); });
    return btn;
  }

  function mapsLink(record) {
    if (record.address) return 'https://maps.google.com/?q=' + encodeURIComponent(record.address);
    return null;
  }

  function shareRecord(record) {
    var shareData = { title: record.title, text: record.title, url: record.website || window.location.href };
    if (navigator.share) {
      navigator.share(shareData).catch(function () {});
    } else if (navigator.clipboard) {
      navigator.clipboard.writeText(shareData.url).catch(function () {});
    }
  }

  // "Menu" only makes sense for food/drink businesses — everyone else
  // (HVAC, salons, garages...) gets the same items list labeled "Services"
  // instead. Derived from the record's own category/sector, not a
  // separate admin-set field — one less thing to configure per record,
  // and it can't drift out of sync with what the business actually is.
  var FOOD_CATEGORY_KEYWORDS = ['restaurant', 'bar', 'cafe', 'café', 'catering', 'food', 'drink', 'bakery', 'diner', 'bistro'];
  function itemsLabel(record) {
    var text = ((record.category || '') + ' ' + (record.sector || '') + ' ' + (record.subcategory || '')).toLowerCase();
    for (var i = 0; i < FOOD_CATEGORY_KEYWORDS.length; i++) {
      if (text.indexOf(FOOD_CATEGORY_KEYWORDS[i]) !== -1) return 'Menu';
    }
    return 'Services';
  }

  function buildMapThumb(record) {
    var link = mapsLink(record);
    if (!link) return null;
    var a = document.createElement('a');
    a.href = link;
    a.target = '_blank';
    a.rel = 'noopener';
    a.className = 'killi-map-thumb';
    a.setAttribute('aria-label', 'Open directions to ' + record.title);
    a.innerHTML = '<svg viewBox="0 0 72 72" aria-hidden="true">'
      + '<rect width="72" height="72" fill="#e8eaed"/>'
      + '<path d="M0 16h72M0 38h72M0 58h72" stroke="#d2d5da" stroke-width="2"/>'
      + '<path d="M14 0v72M46 0v72M60 0v72" stroke="#d2d5da" stroke-width="2"/>'
      + '<path d="M0 38h72" stroke="#c9dcf7" stroke-width="4"/>'
      + '<rect x="20" y="20" width="12" height="12" fill="#dbe0e6"/>'
      + '<rect x="50" y="44" width="10" height="10" fill="#dbe0e6"/>'
      + '<path d="M36 22c-7.2 0-12 5.4-12 12 0 8 12 20 12 20s12-12 12-20c0-6.6-4.8-12-12-12Z" fill="#ea4335"/>'
      + '<circle cx="36" cy="34" r="4.5" fill="#fff"/>'
      + '</svg>';
    return a;
  }

  function buildActionRow(record) {
    var row = el('div', 'killi-modal-actions');
    var entries = [
      ['phone', ICONS.phone, 'Call', record.phone ? 'tel:' + record.phone : null, false],
      ['pin', ICONS.pin, 'Directions', mapsLink(record), true],
      ['globe', ICONS.globe, 'Website', record.website || null, true],
      ['share', ICONS.share, 'Share', '#', false],
    ];
    entries.forEach(function (entry) {
      var href = entry[3];
      if (!href) return;
      var a = document.createElement('a');
      a.innerHTML = '<span class="killi-modal-action-icon">' + entry[1] + '</span><span class="killi-modal-action-label">' + entry[2] + '</span>';
      if (entry[0] === 'share') {
        a.href = '#';
        a.addEventListener('click', function (e) { e.preventDefault(); shareRecord(record); });
      } else {
        a.href = href;
        if (entry[4]) { a.target = '_blank'; a.rel = 'noopener'; }
      }
      row.appendChild(a);
    });
    return row;
  }

  function buildRatingBars(breakdown) {
    if (!Array.isArray(breakdown) || !breakdown.length) return null;
    var max = Math.max.apply(null, breakdown) || 1;
    var wrap = el('div', 'killi-rating-bars');
    breakdown.forEach(function (count) {
      var bar = el('div', 'bar');
      bar.style.height = Math.max(4, (count / max) * 100) + '%';
      wrap.appendChild(bar);
    });
    return wrap;
  }

  /** Individual reviews (record.reviews: [{author, rating, date, text}]) — the star-breakdown bars above summarize the numbers, this is what an actual visitor reads. Every field is optional; a review missing text still shows its author/rating/date. */
  function buildReviewsList(reviews) {
    if (!Array.isArray(reviews) || !reviews.length) return null;
    var list = el('div', 'killi-review-list');
    reviews.forEach(function (rev) {
      var card = el('div', 'killi-review-card');
      var initial = (rev.author || '?').trim().charAt(0).toUpperCase();
      card.appendChild(el('div', 'killi-review-avatar', escapeHtml(initial)));
      var body = el('div', 'killi-review-body');
      var metaLine = el('div', 'killi-review-meta-line');
      metaLine.appendChild(el('span', 'killi-review-author', escapeHtml(rev.author || 'Anonymous')));
      body.appendChild(metaLine);
      var sub = el('div', 'killi-review-meta');
      sub.innerHTML = (rev.rating ? starRowHtml(rev.rating) : '') + (rev.date ? ' <span>' + escapeHtml(rev.date) + '</span>' : '');
      body.appendChild(sub);
      if (rev.text) body.appendChild(el('p', 'killi-review-text', escapeHtml(rev.text)));
      card.appendChild(body);
      list.appendChild(card);
    });
    return list;
  }

  function buildPhotoStrip(photos) {
    if (!Array.isArray(photos) || !photos.length) return null;
    var strip = el('div', 'killi-photo-strip');
    photos.slice(0, 6).forEach(function (src) {
      var img = document.createElement('img');
      img.src = src;
      img.alt = '';
      img.loading = 'lazy';
      strip.appendChild(img);
    });
    return strip;
  }

  function buildTabs(tabNames, onSwitch) {
    var tabs = el('div', 'killi-modal-tabs');
    tabNames.forEach(function (name, i) {
      var tab = el('button', 'killi-modal-tab' + (i === 0 ? ' current' : ''), escapeHtml(name));
      tab.type = 'button';
      tab.addEventListener('click', function () {
        Array.prototype.forEach.call(tabs.querySelectorAll('.killi-modal-tab'), function (t) { t.classList.remove('current'); });
        tab.classList.add('current');
        onSwitch(name);
      });
      tabs.appendChild(tab);
    });
    return tabs;
  }

  function buildBusinessProfileBody(record) {
    var body = document.createDocumentFragment();

    var header = el('div', 'killi-modal-header');
    header.appendChild(el('p', 'killi-modal-title', escapeHtml(record.title)));
    if (record.rating) {
      var ratingParts = [Number(record.rating).toFixed(1), starRowHtml(record.rating)];
      if (record.review_count) ratingParts.push('(' + record.review_count + ')');
      header.appendChild(el('p', 'killi-modal-subline', ratingParts.join(' ')));
    }
    // "<category> business in <location>" reads as one natural sentence,
    // the way a real business-profile card describes itself — falls back
    // gracefully to whichever half is actually available.
    var categoryLocation = record.category && record.location ? record.category + ' business in ' + record.location
      : (record.category || record.location || null);
    var subline2Parts = [];
    if (record.price_range) subline2Parts.push(escapeHtml(record.price_range));
    if (categoryLocation) subline2Parts.push(escapeHtml(categoryLocation));
    if (subline2Parts.length) header.appendChild(el('p', 'killi-modal-subline', subline2Parts.join(' · ')));
    if (record.hours_today) {
      header.appendChild(el('p', 'killi-modal-subline', '<span class="' + (record.is_open_now ? 'killi-modal-status-open' : '') + '">' + escapeHtml(record.hours_today) + '</span>'));
    }
    body.appendChild(header);

    var itemsTabLabel = itemsLabel(record);
    var sections = {};
    body.appendChild(buildTabs(['Overview', 'Reviews', 'Photos', itemsTabLabel], function (name) {
      Object.keys(sections).forEach(function (key) { sections[key].classList.toggle('current', key === name); });
    }));

    var overviewSection = el('div', 'killi-tab-section current');
    var photoStrip = buildPhotoStrip(record.photos);
    if (photoStrip) overviewSection.appendChild(photoStrip);
    overviewSection.appendChild(buildActionRow(record));
    if (record.description) {
      var tagline = el('div', 'killi-modal-tagline');
      tagline.innerHTML = '<span>' + escapeHtml(record.description) + '</span>' + ICONS.chevron;
      overviewSection.appendChild(tagline);
    }
    if (record.order_online_url) {
      var cta = document.createElement('a');
      cta.className = 'killi-modal-cta';
      cta.href = record.order_online_url;
      cta.target = '_blank';
      cta.rel = 'noopener';
      cta.textContent = 'Order online';
      overviewSection.appendChild(cta);
    }
    var infoGrid = el('div', 'killi-info-grid');
    if (Array.isArray(record.photos) && record.photos.length) {
      var galleryTile = el('div', 'killi-info-tile');
      galleryTile.appendChild(el('div', 'killi-info-tile-label', 'Gallery'));
      var galleryThumbs = el('div', 'killi-menu-thumbs');
      record.photos.slice(0, 3).forEach(function (src) {
        var img = document.createElement('img');
        img.src = src;
        img.alt = '';
        galleryThumbs.appendChild(img);
      });
      galleryTile.appendChild(galleryThumbs);
      infoGrid.appendChild(galleryTile);
    }
    if (record.rating) {
      var reviewTile = el('div', 'killi-info-tile');
      reviewTile.appendChild(el('div', 'killi-info-tile-label', 'Reviews'));
      var ratingBig = el('div', 'killi-rating-big', Number(record.rating).toFixed(1) + ' ' + starRowHtml(record.rating));
      reviewTile.appendChild(ratingBig);
      var bars = buildRatingBars(record.rating_breakdown);
      if (bars) reviewTile.appendChild(bars);
      infoGrid.appendChild(reviewTile);
    }
    if (infoGrid.children.length) overviewSection.appendChild(infoGrid);
    if (record.hours_today) {
      overviewSection.appendChild(el('div', 'killi-modal-footer', '<span class="' + (record.is_open_now ? 'killi-modal-status-open' : '') + '">' + escapeHtml(record.hours_today) + '</span>'));
    }
    if (record.address) {
      var locationCard = el('div', 'killi-modal-location');
      var locationText = el('div', 'killi-modal-location-text');
      locationText.innerHTML = '<strong>' + escapeHtml(record.title) + '</strong><br>' + escapeHtml(record.address);
      locationCard.appendChild(locationText);
      var mapThumb = buildMapThumb(record);
      if (mapThumb) locationCard.appendChild(mapThumb);
      overviewSection.appendChild(locationCard);
    }
    body.appendChild(overviewSection);

    var reviewsSection = el('div', 'killi-tab-section');
    if (record.rating) {
      var reviewsHead = el('div', 'killi-info-tile');
      reviewsHead.appendChild(el('div', 'killi-rating-big', Number(record.rating).toFixed(1) + ' ' + starRowHtml(record.rating) + (record.review_count ? ' <span style="font-size:13px;font-weight:400">(' + record.review_count + ')</span>' : '')));
      var reviewsBars = buildRatingBars(record.rating_breakdown);
      if (reviewsBars) reviewsHead.appendChild(reviewsBars);
      reviewsSection.appendChild(reviewsHead);
    } else {
      reviewsSection.appendChild(el('p', 'killi-modal-footer', 'No reviews yet.'));
    }
    var reviewsList = buildReviewsList(record.reviews);
    if (reviewsList) reviewsSection.appendChild(reviewsList);
    body.appendChild(reviewsSection);

    var photosSection = el('div', 'killi-tab-section');
    var fullStrip = buildPhotoStrip(record.photos);
    if (fullStrip) {
      fullStrip.style.height = 'auto';
      fullStrip.style.flexWrap = 'wrap';
      Array.prototype.forEach.call(fullStrip.querySelectorAll('img'), function (img) {
        img.style.width = 'calc(50% - 2px)';
        img.style.height = '120px';
      });
      photosSection.appendChild(fullStrip);
    } else {
      photosSection.appendChild(el('p', 'killi-modal-footer', 'No photos yet.'));
    }
    body.appendChild(photosSection);

    var menuSection = el('div', 'killi-tab-section');
    if (Array.isArray(record.menu_items) && record.menu_items.length) {
      var menuList = el('div', 'killi-item-list');
      record.menu_items.forEach(function (item) {
        var row = el('div', 'killi-item-row');
        if (item.photo) {
          var mi = document.createElement('img');
          mi.src = item.photo;
          mi.alt = '';
          row.appendChild(mi);
        } else {
          row.appendChild(el('div', 'killi-item-row-fallback', ICONS.cart));
        }
        row.appendChild(el('div', '', '<div class="killi-item-row-title">' + escapeHtml(item.name || '') + '</div>'));
        menuList.appendChild(row);
      });
      menuSection.appendChild(menuList);
    } else {
      menuSection.appendChild(el('p', 'killi-modal-footer', itemsTabLabel === 'Menu' ? 'No menu yet.' : 'No services listed yet.'));
    }
    body.appendChild(menuSection);

    sections.Overview = overviewSection;
    sections.Reviews = reviewsSection;
    sections.Photos = photosSection;
    sections[itemsTabLabel] = menuSection;

    return body;
  }

  function buildMenuCatalogBody(record) {
    var body = document.createDocumentFragment();

    var header = el('div', 'killi-modal-header');
    header.appendChild(el('p', 'killi-modal-title', escapeHtml(record.title)));
    var sublineParts = [];
    if (record.price) sublineParts.push('<strong>' + escapeHtml(String(record.price)) + '</strong>');
    if (record.stock_status) sublineParts.push(escapeHtml(record.stock_status));
    if (record.rating) sublineParts.push('<span class="killi-star">' + ICONS.star + '</span> ' + Number(record.rating).toFixed(1));
    if (record.category) sublineParts.push(escapeHtml(record.category));
    header.appendChild(el('p', 'killi-modal-subline', sublineParts.join(' · ')));
    body.appendChild(header);

    var photoStrip = buildPhotoStrip(record.photos);
    if (photoStrip) body.appendChild(photoStrip);

    if (record.description) {
      var desc = el('p', 'killi-modal-footer', escapeHtml(record.description));
      desc.style.display = 'block';
      body.appendChild(desc);
    }

    if (Array.isArray(record.variants) && record.variants.length) {
      var variantList = el('div', 'killi-item-list');
      record.variants.forEach(function (variant) {
        var row = el('div', 'killi-item-row');
        row.appendChild(el('div', '', '<div class="killi-item-row-title">' + escapeHtml(variant.label || '') + '</div>' + (variant.extra_price ? '<div class="killi-item-row-sub">' + escapeHtml(variant.extra_price) + '</div>' : '')));
        variantList.appendChild(row);
      });
      body.appendChild(variantList);
    }

    body.appendChild(buildActionRow(record));

    return body;
  }

  // "Custom" layout: same 6 building blocks as the 2 fixed rich layouts,
  // just admin-selected and always rendered in one fixed order (photos,
  // pricing, rating, hours, items, cta) — recomposing existing renderer
  // functions rather than any new per-admin rendering code. A slot with
  // no matching field on the record is skipped, same as everywhere else.
  var CUSTOM_SLOT_BUILDERS = {
    photos: function (record) { return buildPhotoStrip(record.photos); },
    pricing: function (record) {
      var parts = [];
      if (record.price) parts.push('<strong>' + escapeHtml(String(record.price)) + '</strong>');
      else if (record.price_range) parts.push(escapeHtml(record.price_range));
      if (record.stock_status) parts.push(escapeHtml(record.stock_status));
      return parts.length ? el('p', 'killi-modal-subline', parts.join(' · ')) : null;
    },
    rating: function (record) {
      if (!record.rating) return null;
      var tile = el('div', 'killi-info-tile');
      tile.appendChild(el('div', 'killi-rating-big', Number(record.rating).toFixed(1) + ' ' + ICONS.star + (record.review_count ? ' <span style="font-size:13px;font-weight:400">(' + record.review_count + ')</span>' : '')));
      var bars = buildRatingBars(record.rating_breakdown);
      if (bars) tile.appendChild(bars);
      return tile;
    },
    hours: function (record) {
      if (!record.hours_today) return null;
      var p = el('p', 'killi-modal-footer', '<span class="' + (record.is_open_now ? 'killi-modal-status-open' : '') + '">' + escapeHtml(record.hours_today) + '</span>');
      p.style.display = 'block';
      return p;
    },
    items: function (record) {
      var items = Array.isArray(record.menu_items) && record.menu_items.length ? record.menu_items
        : (Array.isArray(record.variants) && record.variants.length ? record.variants : null);
      if (!items) return null;
      var list = el('div', 'killi-item-list');
      items.forEach(function (item) {
        var row = el('div', 'killi-item-row');
        if (item.photo) {
          var img = document.createElement('img');
          img.src = item.photo;
          img.alt = '';
          row.appendChild(img);
        }
        row.appendChild(el('div', '', '<div class="killi-item-row-title">' + escapeHtml(item.name || item.label || '') + '</div>' + (item.extra_price ? '<div class="killi-item-row-sub">' + escapeHtml(item.extra_price) + '</div>' : '')));
        list.appendChild(row);
      });
      return list;
    },
    cta: function (record) {
      if (!record.order_online_url) return null;
      var cta = document.createElement('a');
      cta.className = 'killi-modal-cta';
      cta.href = record.order_online_url;
      cta.target = '_blank';
      cta.rel = 'noopener';
      cta.textContent = 'Order online';
      return cta;
    },
  };

  function buildCustomBody(record) {
    var body = document.createDocumentFragment();

    var header = el('div', 'killi-modal-header');
    header.appendChild(el('p', 'killi-modal-title', escapeHtml(record.title)));
    var headParts = [];
    if (record.category) headParts.push(escapeHtml(record.category));
    if (record.description) headParts.push(escapeHtml(record.description));
    if (headParts.length) header.appendChild(el('p', 'killi-modal-subline', headParts.join(' · ')));
    body.appendChild(header);

    var slots = Array.isArray(record._customSlots) ? record._customSlots : [];
    slots.forEach(function (slot) {
      var builder = CUSTOM_SLOT_BUILDERS[slot];
      if (!builder) return;
      var node = builder(record);
      if (node) body.appendChild(node);
    });

    body.appendChild(buildActionRow(record));

    return body;
  }

  function openRecordModal(record) {
    var overlay = el('div', 'killi-modal-overlay');
    var modal = el('div', 'killi-modal');
    var closeBtn = el('button', 'killi-modal-close', '&times;');
    closeBtn.type = 'button';
    closeBtn.setAttribute('aria-label', 'Close');
    modal.appendChild(closeBtn);

    var bodyBuilder = record._layout === 'menu_catalog' ? buildMenuCatalogBody
      : record._layout === 'custom' ? buildCustomBody
      : buildBusinessProfileBody;
    modal.appendChild(bodyBuilder(record));

    // Keep the header/tabs fixed and move everything else into a
    // scrolling wrapper — otherwise the modal's own height would follow
    // whichever tab's content is tallest, resizing on every tab switch.
    var scrollBody = el('div', 'killi-modal-body');
    Array.prototype.slice.call(modal.children).forEach(function (child) {
      if (child === closeBtn || child.classList.contains('killi-modal-header') || child.classList.contains('killi-modal-tabs')) return;
      scrollBody.appendChild(child);
    });
    modal.appendChild(scrollBody);

    overlay.appendChild(modal);
    function close() { overlay.remove(); document.removeEventListener('keydown', onKey); }
    function onKey(e) { if (e.key === 'Escape') close(); }
    closeBtn.addEventListener('click', close);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) close(); });
    document.addEventListener('keydown', onKey);

    document.body.appendChild(overlay);

    // Lock the modal to a height that fits its content, measured once
    // here (so it never resizes mid-switch) rather than a flat constant —
    // a "Simple" record with almost nothing to show would otherwise sit
    // inside the same tall box as a fully-populated one, all empty space
    // below a few lines of content.
    var tabSections = scrollBody.querySelectorAll('.killi-tab-section');
    var contentHeight = 0;
    if (tabSections.length) {
      Array.prototype.forEach.call(tabSections, function (sec) {
        var wasCurrent = sec.classList.contains('current');
        sec.classList.add('current');
        contentHeight = Math.max(contentHeight, sec.scrollHeight);
        if (!wasCurrent) sec.classList.remove('current');
      });
    } else {
      contentHeight = scrollBody.scrollHeight;
    }
    var header = modal.querySelector('.killi-modal-header');
    var tabsBar = modal.querySelector('.killi-modal-tabs');
    var chromeHeight = (header ? header.offsetHeight : 0) + (tabsBar ? tabsBar.offsetHeight : 0);
    var desired = chromeHeight + contentHeight + 4;
    var ceiling = Math.min(600, window.innerHeight * 0.88);
    modal.style.height = Math.max(Math.min(desired, ceiling), 180) + 'px';

    document.body.appendChild(overlay);
  }

  function renderResults(records) {
    var wrap = el('div', 'killi-results');
    if (records.length === 0) {
      wrap.appendChild(el('div', 'killi-empty', 'No matches yet — try a different word or category.'));
    } else {
      records.slice(0, 6).forEach(function (r) {
        wrap.appendChild(renderCard(r));
      });
    }
    chat.appendChild(wrap);
    scrollToBottom();
    return wrap;
  }

  function detectedBreadcrumb(detected) {
    if (!detected) return '';
    var parts = [];
    if (detected.sector) parts.push('Sector: ' + detected.sector);
    if (detected.category) parts.push('Category: ' + (detected.subcategory || detected.category));
    if (detected.location) parts.push('Location: ' + detected.location);
    return parts.join(' · ');
  }

  function defaultSummary(query, count) {
    return count === 0
      ? 'I couldn’t find anything for “' + query + '”.'
      : 'I found ' + count + (count === 1 ? ' result' : ' results') + ' for “' + query + '”.';
  }

  // Shared by runSearch (structured chip/near-me follow-ups) and runChat
  // (free-text messages) so "Highest rated" / "Verified only" / "Near me"
  // behave the same regardless of how the results got on screen.
  function buildQuickReplies(query, meta) {
    var replies = [
      {
        label: 'Highest rated',
        onClick: function () {
          var sorted = lastResults.slice().sort(function (a, b) { return (b.rating || 0) - (a.rating || 0); });
          addBubble('user', 'Highest rated');
          addBubble('ai', 'Here they are, sorted by rating:');
          renderResults(sorted);
        },
      },
      {
        label: 'Verified only',
        onClick: function () {
          var verified = lastResults.filter(function (r) { return r.verified; });
          addBubble('user', 'Verified only');
          addBubble('ai', verified.length ? 'Showing verified listings only:' : 'None of these are verified yet.');
          renderResults(verified);
        },
      },
    ];

    if (!(meta && meta.near) && navigator.geolocation) {
      replies.push({
        label: 'Near me',
        onClick: function () {
          addBubble('user', 'Near me');
          navigator.geolocation.getCurrentPosition(
            function (pos) {
              runSearch(query, { lat: pos.coords.latitude, lng: pos.coords.longitude });
            },
            function () {
              addBubble('ai', 'I couldn’t access your location — please allow location access and try again.');
            }
          );
        },
      });
    }

    return replies;
  }

  // Structured search: category/sector chips and the "Near me" follow-up.
  // Hits search.php directly with explicit filters rather than going
  // through intent detection.
  function runSearch(query, filters) {
    filters = filters || {};
    var params = new URLSearchParams({ q: query, limit: '10' });
    if (filters.category) params.set('category', filters.category);
    if (filters.sector) params.set('sector', filters.sector);
    if (filters.location) params.set('location', filters.location);
    if (filters.lat != null && filters.lng != null) {
      params.set('lat', filters.lat);
      params.set('lng', filters.lng);
    }

    showTyping();
    return fetch(API_BASE + 'search.php?' + params.toString())
      .then(function (res) { return res.json(); })
      .then(function (payload) {
        hideTyping();
        markDelivered();
        if (!payload.success) {
          addBubble('ai', payload.error && payload.error.message ? payload.error.message : 'Something went wrong.');
          return;
        }
        lastResults = payload.data;
        var count = payload.meta.total;
        var breadcrumb = detectedBreadcrumb(payload.meta.detected);
        if (breadcrumb) addBubble('ai', breadcrumb);
        addBubble('ai', payload.meta.reply || defaultSummary(query, count), true);
        renderResults(payload.data);

        if (count > 1) {
          addQuickReplies(buildQuickReplies(query, payload.meta));
        }
      })
      .catch(function () {
        hideTyping();
        addBubble('ai', 'I’m having trouble reaching the search service. Please try again.');
      });
  }

  // Free-text messages: routed through the rule-based (no AI/LLM)
  // ConversationEngine on the server, which handles small talk
  // (greeting/thanks/help) as well as "find_service" queries — the
  // reply text itself comes from config/conversation.json, so an admin
  // can edit tone/wording without touching this file.
  function runChat(message) {
    showTyping();
    return fetch(API_BASE + 'chat.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ message: message }),
    })
      .then(function (res) { return res.json(); })
      .then(function (payload) {
        hideTyping();
        if (!payload.success && payload.error && payload.error.code === 'AUTH_REQUIRED') {
          showUnlockPrompt(function () { runChat(message); });
          return;
        }
        markDelivered();
        if (!payload.success) {
          addBubble('ai', payload.error && payload.error.message ? payload.error.message : 'Something went wrong.');
          return;
        }
        var data = payload.data;

        // Conversational forms (data.intent === 'form') run one question
        // per turn — no results/quick-replies while a form is active, and
        // the user's next typed message is treated as the answer, not a
        // new search (enforced server-side via the PHP session).
        if (data.intent === 'form' || data.intent === 'form_complete') {
          if (data.reply) addBubble('ai', data.reply);
          if (data.form && data.form.active && data.form.field) {
            addBubble('ai', data.form.field.label);
          }
          return;
        }

        lastResults = data.results || [];

        var breadcrumb = detectedBreadcrumb(data.detected);
        if (breadcrumb) addBubble('ai', breadcrumb);
        addBubble('ai', data.reply || defaultSummary(message, data.total), true);

        if (data.image) {
          var media = document.createElement('img');
          media.src = data.image;
          media.alt = '';
          media.className = 'killi-media';
          chat.appendChild(media);
          scrollToBottom();
        }

        if (data.results && data.results.length) {
          renderResults(data.results);
        }

        if (data.total > 1) {
          addQuickReplies(buildQuickReplies(message, {}));
        } else if (data.offer_ticket) {
          addQuickReplies([{
            label: 'Raise a request',
            onClick: function () {
              addBubble('user', 'Raise a request');
              runChat('raise a request');
            },
          }]);
        }
      })
      .catch(function () {
        hideTyping();
        addBubble('ai', 'I’m having trouble reaching the chat service. Please try again.');
      });
  }

  function handleSubmit(e) {
    e.preventDefault();
    var query = input.value.trim();
    if (!query) return;
    hideSuggestions();
    addBubble('user', query);
    input.value = '';
    runChat(query);
  }

  function hideSuggestions() {
    suggestionsBox.hidden = true;
    suggestionsBox.innerHTML = '';
  }

  function showSuggestions(items) {
    if (!items.length) {
      hideSuggestions();
      return;
    }
    suggestionsBox.innerHTML = '';
    items.forEach(function (text) {
      var item = el('div', 'killi-suggestion-item', escapeHtml(text));
      item.addEventListener('click', function () {
        input.value = text;
        hideSuggestions();
        form.requestSubmit();
      });
      suggestionsBox.appendChild(item);
    });
    suggestionsBox.hidden = false;
  }

  form.addEventListener('submit', handleSubmit);

  input.addEventListener('input', function () {
    var q = input.value.trim();
    clearTimeout(suggestTimer);
    if (q.length < 2) {
      hideSuggestions();
      return;
    }
    suggestTimer = setTimeout(function () {
      fetch(API_BASE + 'suggest.php?q=' + encodeURIComponent(q))
        .then(function (res) { return res.json(); })
        .then(function (payload) { showSuggestions(payload.data || []); })
        .catch(function () { hideSuggestions(); });
    }, 220);
  });

  document.addEventListener('click', function (e) {
    if (!suggestionsBox.contains(e.target) && e.target !== input) {
      hideSuggestions();
    }
  });

  // Chips are prompts, not direct actions: clicking one fills the search
  // input (the single "search prompt" surface) so the user can send it
  // as-is or add more text — e.g. tap "Automotive" then type " in lekki".
  chips.addEventListener('click', function (e) {
    var chip = e.target.closest('.killi-chip');
    if (!chip) return;
    var sector = chip.getAttribute('data-sector');
    input.value = sector + ' ';
    input.focus();
    hideSuggestions();
  });

  // File attachment: pick a file (photo library, camera, or camera video),
  // upload it, then send it to the bot as its own turn (see sendAttachment).
  // Validation (type/size) is enforced server-side in api/upload.php; the
  // accept/capture attributes are just UI hints.
  function uploadAttachment(file) {
    if (!file) return;
    var formData = new FormData();
    formData.append('file', file);
    attachBtn.disabled = true;

    fetch(API_BASE + 'upload.php', { method: 'POST', body: formData })
      .then(function (res) { return res.json(); })
      .then(function (payload) {
        attachBtn.disabled = false;
        if (!payload.success) {
          addBubble('ai', payload.error && payload.error.message ? payload.error.message : 'Could not upload that file.');
          return;
        }
        sendAttachment(payload.data);
      })
      .catch(function () {
        attachBtn.disabled = false;
        addBubble('ai', 'I’m having trouble uploading that file. Please try again.');
      });
  }

  if (attachBtn && attachInput && attachSheet) {
    attachBtn.addEventListener('click', function () { attachSheet.hidden = false; });

    attachSheet.addEventListener('click', function (e) {
      if (e.target === attachSheet) attachSheet.hidden = true;
    });

    var cancelAttach = document.getElementById('killi-attach-cancel');
    if (cancelAttach) cancelAttach.addEventListener('click', function () { attachSheet.hidden = true; });

    Array.prototype.forEach.call(attachSheet.querySelectorAll('.killi-attach-option[data-target]'), function (btn) {
      btn.addEventListener('click', function () {
        attachSheet.hidden = true;
        var target = document.getElementById(btn.getAttribute('data-target'));
        if (target) target.click();
      });
    });

    [attachInput, attachCameraPhoto, attachCameraVideo].forEach(function (fileInput) {
      if (!fileInput) return;
      fileInput.addEventListener('change', function () {
        var file = fileInput.files[0];
        fileInput.value = '';
        uploadAttachment(file);
      });
    });
  }

  // Voice input: Web Speech API, feature-detected. Fills the input rather
  // than auto-submitting — same "review before sending" pattern as chip
  // fill — since misheard transcripts are common and this avoids firing
  // an accidental search.
  var SpeechRecognitionImpl = window.SpeechRecognition || window.webkitSpeechRecognition;
  if (SpeechRecognitionImpl && micBtn) {
    micBtn.hidden = false;
    var recognition = new SpeechRecognitionImpl();
    recognition.continuous = false;
    recognition.interimResults = false;
    recognition.lang = navigator.language || 'en-US';

    recognition.addEventListener('result', function (e) {
      var transcript = e.results[0][0].transcript;
      input.value = transcript;
      input.focus();
    });
    recognition.addEventListener('end', function () { micBtn.classList.remove('listening'); });
    recognition.addEventListener('error', function () { micBtn.classList.remove('listening'); });

    micBtn.addEventListener('click', function () {
      if (micBtn.classList.contains('listening')) {
        recognition.stop();
        return;
      }
      hideSuggestions();
      micBtn.classList.add('listening');
      try {
        recognition.start();
      } catch (err) {
        micBtn.classList.remove('listening');
      }
    });
  }

  // Theme toggle: defaults to the OS/browser preference (handled in CSS),
  // an explicit choice is remembered in localStorage and wins from then on.
  var THEME_KEY = 'killi-theme';

  function systemPrefersDark() {
    return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
  }

  function isDarkActive() {
    var explicit = document.documentElement.getAttribute('data-theme');
    if (explicit === 'dark') return true;
    if (explicit === 'light') return false;
    return systemPrefersDark();
  }

  function applyTheme(theme) {
    if (theme === 'dark' || theme === 'light') {
      document.documentElement.setAttribute('data-theme', theme);
    } else {
      document.documentElement.removeAttribute('data-theme');
    }
    if (themeToggle) themeToggle.textContent = isDarkActive() ? '☀️' : '🌙';
  }

  if (themeToggle) {
    applyTheme(localStorage.getItem(THEME_KEY));
    themeToggle.addEventListener('click', function () {
      var next = isDarkActive() ? 'light' : 'dark';
      localStorage.setItem(THEME_KEY, next);
      applyTheme(next);
    });
  }

  // Rate this conversation: a persistent affordance rather than trying to
  // auto-detect "the user is done" (there's no reliable signal for that in
  // a stateless page) — it only becomes visible once there's at least one
  // finished exchange (sessionTurns, tracked in addBubble above) worth rating.
  (function () {
    var panel = document.getElementById('killi-rate-panel');
    if (!rateBtn || !panel) return;
    var stars = panel.querySelectorAll('.killi-star');
    var comment = document.getElementById('killi-rate-comment');
    var submitBtn = document.getElementById('killi-rate-submit');
    var cancelBtn = document.getElementById('killi-rate-cancel');
    var selected = 0;

    function paintStars() {
      stars.forEach(function (star) {
        star.classList.toggle('filled', Number(star.getAttribute('data-value')) <= selected);
      });
      submitBtn.disabled = selected === 0;
    }

    stars.forEach(function (star) {
      star.addEventListener('click', function () {
        selected = Number(star.getAttribute('data-value'));
        paintStars();
      });
    });

    function closePanel() {
      panel.hidden = true;
      selected = 0;
      comment.value = '';
      paintStars();
    }

    rateBtn.addEventListener('click', function () {
      panel.hidden = !panel.hidden;
    });
    cancelBtn.addEventListener('click', closePanel);

    submitBtn.addEventListener('click', function () {
      if (selected === 0) return;
      submitBtn.disabled = true;
      fetch(API_BASE + 'session_feedback.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ rating: selected, comment: comment.value, turns: sessionTurns }),
      }).then(function () {
        panel.innerHTML = '<div class="killi-rate-thanks">Thanks for the feedback!</div>';
        setTimeout(function () { panel.hidden = true; }, 1600);
      }).catch(function () {
        submitBtn.disabled = false;
      });
    });
  })();

  // Bottom icon nav — Home/Search are real actions; Saved/Profile are
  // placeholders for now (no such feature yet), so they still switch the
  // active state but say so rather than silently doing nothing.
  (function () {
    var nav = document.getElementById('killi-bottom-nav');
    if (!nav) return;
    var navItems = nav.querySelectorAll('.killi-nav-item');
    nav.addEventListener('click', function (e) {
      var btn = e.target.closest('.killi-nav-item');
      if (!btn) return;
      Array.prototype.forEach.call(navItems, function (item) { item.classList.toggle('active', item === btn); });
      var target = btn.getAttribute('data-nav');
      if (target === 'home') {
        chat.scrollTop = 0;
      } else if (target === 'search') {
        input.focus();
      } else {
        addBubble('ai', (target === 'saved' ? 'Saved listings' : 'Profile') + " isn't available in this preview yet.");
      }
    });
  })();

  addBubble('ai', branding.welcome_message || 'Hi, what are you looking for today?');

  // A PWA manifest shortcut (long-press the installed app icon) links to
  // ?sector=X — deep-links the same way clicking that chip would: fills
  // the input, doesn't auto-submit, consistent with "chips are prompts,
  // not direct actions" above.
  var deepLinkSector = new URLSearchParams(window.location.search).get('sector');
  if (deepLinkSector) {
    input.value = deepLinkSector + ' ';
    input.focus();
  }
})();
