(function () {
  'use strict';

  var API_BASE = '../api/';
  var chat = document.getElementById('kili-chat');
  var form = document.getElementById('kili-searchbar');
  var input = document.getElementById('kili-input');
  var suggestionsBox = document.getElementById('kili-suggestions');
  var chips = document.getElementById('kili-chips');
  var branding = window.KILI_BRANDING || {};
  var lastResults = [];
  var suggestTimer = null;

  function el(tag, className, html) {
    var node = document.createElement(tag);
    if (className) node.className = className;
    if (html !== undefined) node.innerHTML = html;
    return node;
  }

  function scrollToBottom() {
    chat.scrollTop = chat.scrollHeight;
  }

  function addBubble(role, text) {
    var bubble = el('div', 'kili-bubble ' + role, escapeHtml(text));
    chat.appendChild(bubble);
    scrollToBottom();
    return bubble;
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

    var top = el('div', 'kili-card-top');
    top.appendChild(el('div', 'kili-card-title', escapeHtml(record.title) + (record.verified ? '<span class="kili-badge">Verified</span>' : '')));
    if (record.rating) top.appendChild(el('div', 'kili-card-rating', starRating(record.rating)));
    card.appendChild(top);

    var metaParts = [record.subcategory || record.category, record.location].filter(Boolean);
    card.appendChild(el('div', 'kili-card-meta', escapeHtml(metaParts.join(' · '))));

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

  function runSearch(query, filters) {
    filters = filters || {};
    var params = new URLSearchParams({ q: query, limit: '10' });
    if (filters.category) params.set('category', filters.category);
    if (filters.location) params.set('location', filters.location);

    return fetch(API_BASE + 'search.php?' + params.toString())
      .then(function (res) { return res.json(); })
      .then(function (payload) {
        if (!payload.success) {
          addBubble('ai', payload.error && payload.error.message ? payload.error.message : 'Something went wrong.');
          return;
        }
        lastResults = payload.data;
        var count = payload.meta.total;
        var summary = count === 0
          ? 'I couldn’t find anything for “' + query + '”.'
          : 'I found ' + count + (count === 1 ? ' result' : ' results') + ' for “' + query + '”.';
        addBubble('ai', summary);
        renderResults(payload.data);

        if (count > 1) {
          addQuickReplies([
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
          ]);
        }
      })
      .catch(function () {
        addBubble('ai', 'I’m having trouble reaching the search service. Please try again.');
      });
  }

  function handleSubmit(e) {
    e.preventDefault();
    var query = input.value.trim();
    if (!query) return;
    hideSuggestions();
    addBubble('user', query);
    input.value = '';
    runSearch(query);
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

  chips.addEventListener('click', function (e) {
    var chip = e.target.closest('.kili-chip');
    if (!chip) return;
    var category = chip.getAttribute('data-category');
    addBubble('user', category);
    runSearch(category, { category: category });
  });

  addBubble('ai', branding.welcome_message || 'Hi, what are you looking for today?');
})();
