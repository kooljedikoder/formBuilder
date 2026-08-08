(function () {
  'use strict';

  var API_BASE = '../api/';
  var chat = document.getElementById('kili-chat');
  var form = document.getElementById('kili-searchbar');
  var input = document.getElementById('kili-input');
  var suggestionsBox = document.getElementById('kili-suggestions');
  var chips = document.getElementById('kili-chips');
  var themeToggle = document.getElementById('kili-theme-toggle');
  var attachBtn = document.getElementById('kili-attach');
  var attachInput = document.getElementById('kili-attach-input');
  var micBtn = document.getElementById('kili-mic');
  var branding = window.KILI_BRANDING || {};
  var lastResults = [];
  var suggestTimer = null;
  var lastUserTick = null;

  function el(tag, className, html) {
    var node = document.createElement(tag);
    if (className) node.className = className;
    if (html !== undefined) node.innerHTML = html;
    return node;
  }

  function scrollToBottom() {
    chat.scrollTop = chat.scrollHeight;
  }

  var REACTION_EMOJI = ['😍', '👍', '😐', '👎'];

  function buildReactionRow(replyText) {
    var wrap = el('div', 'kili-reactions');
    REACTION_EMOJI.forEach(function (emoji) {
      var btn = el('button', 'kili-reaction', emoji);
      btn.type = 'button';
      btn.setAttribute('aria-label', 'React with ' + emoji);
      btn.addEventListener('click', function () {
        Array.prototype.forEach.call(wrap.querySelectorAll('.kili-reaction'), function (b) {
          b.classList.remove('selected');
        });
        btn.classList.add('selected');
        fetch(API_BASE + 'feedback.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ emoji: emoji, reply: replyText }),
        }).catch(function () {});
      });
      wrap.appendChild(btn);
    });
    return wrap;
  }

  /** withReaction adds an emoji-reaction row under this bubble — used for "final answer" AI replies, not breadcrumbs or mid-form questions. */
  function addBubble(role, text, withReaction) {
    var bubble = el('div', 'kili-bubble ' + role, escapeHtml(text));
    if (role === 'user') {
      var tick = el('span', 'kili-tick', '&#10003;');
      bubble.appendChild(tick);
      lastUserTick = tick;
    }
    chat.appendChild(bubble);
    if (role === 'ai' && withReaction) {
      chat.appendChild(buildReactionRow(text));
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
    var bubble = el('div', 'kili-bubble ai kili-typing', '<span></span><span></span><span></span>');
    bubble.id = 'kili-typing-bubble';
    chat.appendChild(bubble);
    scrollToBottom();
  }

  function hideTyping() {
    var existing = document.getElementById('kili-typing-bubble');
    if (existing) existing.remove();
  }

  function addAttachmentBubble(file) {
    var bubble = el('div', 'kili-bubble user kili-attachment');
    if (file.mime && file.mime.indexOf('image/') === 0) {
      var img = document.createElement('img');
      img.src = file.url;
      img.alt = file.filename || 'Attachment';
      img.className = 'kili-attachment-img';
      bubble.appendChild(img);
    } else {
      var link = el('a', 'kili-attachment-file', '&#128196; ' + escapeHtml(file.filename || 'Attachment'));
      link.href = file.url;
      link.target = '_blank';
      link.rel = 'noopener';
      bubble.appendChild(link);
    }
    var tick = el('span', 'kili-tick', '&#10003;');
    bubble.appendChild(tick);
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
    var wrap = el('div', 'kili-quick-replies');
    replies.forEach(function (r) {
      var btn = el('button', 'kili-quick-reply', escapeHtml(r.label));
      btn.type = 'button';
      btn.addEventListener('click', r.onClick);
      wrap.appendChild(btn);
    });
    chat.appendChild(wrap);
    scrollToBottom();
    return wrap;
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
    var card = el('div', 'kili-card');

    if (record.image) {
      var thumb = document.createElement('img');
      thumb.src = record.image;
      thumb.alt = record.title;
      thumb.className = 'kili-card-image';
      card.appendChild(thumb);
    }

    var top = el('div', 'kili-card-top');
    top.appendChild(el('div', 'kili-card-title', escapeHtml(record.title) + (record.verified ? '<span class="kili-badge">Verified</span>' : '')));
    if (record.rating) top.appendChild(el('div', 'kili-card-rating', starRating(record.rating)));
    card.appendChild(top);

    var metaParts = [record.subcategory || record.category, record.location].filter(Boolean);
    if (record._distance_km !== undefined) metaParts.push(record._distance_km + ' km away');
    card.appendChild(el('div', 'kili-card-meta', escapeHtml(metaParts.join(' · '))));

    if (record.source && record.source.name) {
      card.appendChild(el('div', 'kili-card-source', 'Source: ' + escapeHtml(record.source.name)));
    }

    var actions = el('div', 'kili-card-actions');
    if (record.phone) {
      var call = el('a', 'kili-action call', 'Call');
      call.href = 'tel:' + record.phone;
      actions.appendChild(call);
    }
    if (record.whatsapp) {
      var wa = el('a', 'kili-action whatsapp', 'WhatsApp');
      wa.href = waLink(record.whatsapp);
      wa.target = '_blank';
      wa.rel = 'noopener';
      actions.appendChild(wa);
    }
    if (record.website) {
      var site = el('a', 'kili-action website', 'Website');
      site.href = record.website;
      site.target = '_blank';
      site.rel = 'noopener';
      actions.appendChild(site);
    }
    card.appendChild(actions);

    return card;
  }

  function renderResults(records) {
    var wrap = el('div', 'kili-results');
    if (records.length === 0) {
      wrap.appendChild(el('div', 'kili-empty', 'No matches yet — try a different word or category.'));
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
          media.className = 'kili-media';
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
      var item = el('div', 'kili-suggestion-item', escapeHtml(text));
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
    var chip = e.target.closest('.kili-chip');
    if (!chip) return;
    var sector = chip.getAttribute('data-sector');
    input.value = sector + ' ';
    input.focus();
    hideSuggestions();
  });

  // File attachment: pick a file, upload it, then send it to the bot as
  // its own turn (see sendAttachment). Validation (type/size) is enforced
  // server-side in api/upload.php; the accept="" attribute is just a UI hint.
  if (attachBtn && attachInput) {
    attachBtn.addEventListener('click', function () { attachInput.click(); });

    attachInput.addEventListener('change', function () {
      var file = attachInput.files[0];
      if (!file) return;

      var formData = new FormData();
      formData.append('file', file);
      attachBtn.disabled = true;

      fetch(API_BASE + 'upload.php', { method: 'POST', body: formData })
        .then(function (res) { return res.json(); })
        .then(function (payload) {
          attachBtn.disabled = false;
          attachInput.value = '';
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
  var THEME_KEY = 'kili-theme';

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

  addBubble('ai', branding.welcome_message || 'Hi, what are you looking for today?');
})();
