(function () {
  'use strict';

  var cfg = window.SIG || {};
  var root = document.getElementById('sig-dashboard');
  if (!root) {
    return;
  }

  var rest = cfg.rest || '';
  var nonce = cfg.nonce || '';
  var chartUrl = cfg.chartUrl || '/?pagename=chart';
  var state = {
    asOf: null,
    watchlist: [],
    signals: [],
    counts: { dreamteam: 0, buy: 0, sell: 0, watch: 0, all: 0 },
    filter: 'dreamteam',
    universe: [],
    capital: 100000,
    error: '',
    q: '',
    sort: { key: 'symbol', dir: 'asc' }
  };

  try {
    var savedSort = JSON.parse(localStorage.getItem('sig-sort') || 'null');
    if (savedSort && typeof savedSort.key === 'string') {
      var allowed = { symbol: 1, signal: 1, ret_1d: 1, ret_6m: 1, close: 1, shares: 1, value: 1 };
      if (allowed[savedSort.key]) {
        state.sort.key = savedSort.key;
        state.sort.dir = savedSort.dir === 'desc' ? 'desc' : 'asc';
      }
    }
  } catch (e) {
    /* ignore */
  }

  function api(path, opts) {
    opts = opts || {};
    var headers = {
      Accept: 'application/json',
      'X-WP-Nonce': nonce
    };
    if (opts.body && typeof opts.body === 'string') {
      headers['Content-Type'] = 'application/json';
    }
    var url = rest + path;
    if (opts.query && typeof opts.query === 'object') {
      var qs = Object.keys(opts.query).map(function (k) {
        return encodeURIComponent(k) + '=' + encodeURIComponent(opts.query[k]);
      }).join('&');
      if (qs) {
        url += (url.indexOf('?') >= 0 ? '&' : '?') + qs;
      }
    }
    return fetch(url, {
      method: opts.method || 'GET',
      credentials: 'same-origin',
      headers: headers,
      body: opts.body || null
    }).then(function (res) {
      return res.json().catch(function () {
        return {};
      }).then(function (body) {
        if (!res.ok) {
          var msg = (body && (body.message || body.error)) || ('HTTP ' + res.status);
          throw new Error(msg);
        }
        return body;
      });
    });
  }

  function bySymbol(rows) {
    var map = {};
    (rows || []).forEach(function (r) {
      map[String(r.symbol).toUpperCase()] = r;
    });
    return map;
  }

  function inDreamteam(symbol) {
    var want = String(symbol || '').toUpperCase();
    return (state.watchlist || []).some(function (s) {
      return String(s).toUpperCase() === want;
    });
  }

  function fmtPrice(v) {
    if (v === null || v === undefined || v === '') {
      return '—';
    }
    var n = Number(v);
    if (isNaN(n)) {
      return String(v);
    }
    return n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 4 });
  }

  function fmtMoney(v) {
    var n = Math.round(Number(v) || 0);
    return '$' + n.toLocaleString();
  }

  function fmtRet(v) {
    if (v === null || v === undefined || v === '') {
      return '—';
    }
    var n = Number(v);
    if (isNaN(n)) {
      return '—';
    }
    var s = n.toFixed(1) + '%';
    if (n > 0) {
      s = '+' + s;
    }
    return s;
  }

  function retClass(v) {
    if (v === null || v === undefined || v === '') {
      return '';
    }
    var n = Number(v);
    if (isNaN(n)) {
      return '';
    }
    if (n > 0) {
      return ' sig-up';
    }
    if (n < 0) {
      return ' sig-down';
    }
    return '';
  }

  function pill(signal) {
    var s = signal || 'none';
    return '<span class="sig-pill ' + s + '">' + s + '</span>';
  }

  function chartLink(symbol) {
    var join = chartUrl.indexOf('?') >= 0 ? '&' : '?';
    return chartUrl + join + 'symbol=' + encodeURIComponent(symbol);
  }

  function positionSize(price, ema65, capital) {
    var cap = Number(capital);
    if (!isFinite(cap) || cap <= 0) {
      cap = 100000;
    }
    var risk = cap * 0.01;
    var maxPos = cap * 0.25;
    var p = Number(price);
    var e = Number(ema65);
    var shares = 0;
    if (isFinite(p) && p > 0 && isFinite(e) && p > e) {
      var dist = p - e;
      if (dist > 0) {
        shares = Math.floor(risk / dist);
      }
    }
    if (shares < 0 || !isFinite(shares)) {
      shares = 0;
    }
    if (p > 0 && shares * p > maxPos) {
      shares = Math.floor(maxPos / p);
    }
    var value = (shares && p) ? Math.round((shares * p) / 10) * 10 : 0;
    return { shares: shares, value: value, risk: risk, maxPos: maxPos };
  }

  function setText(sel, text) {
    var el = root.querySelector(sel);
    if (el) {
      el.textContent = text;
    }
  }

  function syncCapitalFields() {
    var size = positionSize(1, 0, state.capital);
    setText('[data-risk]', fmtMoney(size.risk));
    setText('[data-max]', fmtMoney(size.maxPos));
    root.querySelectorAll('[data-capital]').forEach(function (input) {
      if (document.activeElement === input) {
        return;
      }
      if (String(input.value) !== String(state.capital)) {
        input.value = state.capital;
      }
    });
  }


  function sortRows(rows) {
    var key = (state.sort && state.sort.key) || 'symbol';
    var dir = state.sort && state.sort.dir === 'desc' ? -1 : 1;
    var copy = (rows || []).slice();
    var rank = { buy: 0, watch: 1, sell: 2, exit: 2, none: 3 };
    copy.sort(function (a, b) {
      var av, bv, an, bn;
      if (key === 'symbol') {
        av = String(a.symbol || '').toUpperCase();
        bv = String(b.symbol || '').toUpperCase();
        if (av < bv) { return -1 * dir; }
        if (av > bv) { return 1 * dir; }
        return 0;
      }
      if (key === 'signal') {
        an = rank[a.signal] != null ? rank[a.signal] : 9;
        bn = rank[b.signal] != null ? rank[b.signal] : 9;
        if (an !== bn) { return (an - bn) * dir; }
        return String(a.symbol || '').localeCompare(String(b.symbol || ''));
      }
      if (key === 'shares' || key === 'value') {
        an = positionSize(a.close, a.ema65, state.capital)[key] || 0;
        bn = positionSize(b.close, b.ema65, state.capital)[key] || 0;
      } else if (key === 'close') {
        an = Number(a.close);
        bn = Number(b.close);
      } else if (key === 'ret_1d') {
        an = Number(a.ret_1d);
        bn = Number(b.ret_1d);
      } else {
        an = Number(a.ret_6m);
        bn = Number(b.ret_6m);
      }
      var aMiss = !isFinite(an);
      var bMiss = !isFinite(bn);
      if (aMiss && bMiss) {
        return String(a.symbol || '').localeCompare(String(b.symbol || ''));
      }
      if (aMiss) { return 1; }
      if (bMiss) { return -1; }
      if (an !== bn) { return (an - bn) * dir; }
      return String(a.symbol || '').localeCompare(String(b.symbol || ''));
    });
    return copy;
  }

  function syncSortUi() {
    root.querySelectorAll('[data-sort]').forEach(function (el) {
      var on = el.getAttribute('data-sort') === state.sort.key;
      el.classList.toggle('is-on', on);
      el.setAttribute('aria-sort', on ? (state.sort.dir === 'desc' ? 'descending' : 'ascending') : 'none');
    });
  }

  function toggleSort(key) {
    if (!key) { return; }
    if (state.sort.key === key) {
      state.sort.dir = state.sort.dir === 'asc' ? 'desc' : 'asc';
    } else {
      state.sort.key = key;
      state.sort.dir = (key === 'symbol' || key === 'signal') ? 'asc' : 'desc';
    }
    try {
      localStorage.setItem('sig-sort', JSON.stringify(state.sort));
    } catch (e) {
      /* ignore */
    }
    render();
  }

  function render() {
    var c = state.counts || {};
    setText('[data-stat="dreamteam"]', String(c.dreamteam != null ? c.dreamteam : 0));
    setText('[data-stat="buy"]', String(c.buy != null ? c.buy : 0));
    setText('[data-stat="sell"]', String(c.sell != null ? c.sell : 0));
    setText('[data-stat="watch"]', String(c.watch != null ? c.watch : 0));
    setText('[data-stat="all"]', String(c.all != null ? c.all : 0));
    setText('[data-asof]', state.asOf ? ('As of ' + state.asOf) : 'As of —');
    syncCapitalFields();

    root.querySelectorAll('[data-filter]').forEach(function (box) {
      box.classList.toggle('is-on', box.getAttribute('data-filter') === state.filter);
    });

    var body = root.querySelector('[data-rows]');
    var empty = root.querySelector('[data-empty]');
    var err = root.querySelector('[data-error]');
    if (err) {
      err.textContent = state.error || '';
      err.hidden = !state.error;
    }
    if (!body) {
      return;
    }
    syncSortUi();
    var qEl = root.querySelector('[data-q]');
    if (qEl && document.activeElement !== qEl && qEl.value !== state.q) {
      qEl.value = state.q;
    }
    var rows = sortRows(state.signals || []);
    var q = String(state.q || '').trim().toUpperCase();
    if (q) {
      rows = rows.filter(function (r) {
        return String(r.symbol || '').toUpperCase().indexOf(q) !== -1
          || String(r.signal || '').toUpperCase().indexOf(q) !== -1;
      });
    }
    if (!rows.length) {
      body.innerHTML = '';
      if (empty) {
        empty.hidden = false;
        if (q) {
          empty.textContent = 'No tickers match “' + state.q + '”.';
        } else if (state.filter === 'dreamteam' && !(state.watchlist && state.watchlist.length)) {
          empty.textContent = 'Your Dreamteam is empty. Add a symbol or use a starter pack.';
        } else {
          empty.textContent = 'No symbols match this filter.';
        }
      }
      return;
    }
    if (empty) {
      empty.hidden = true;
    }
    body.innerHTML = rows.map(function (r) {
      var sym = r.symbol;
      var size = positionSize(r.close, r.ema65, state.capital);
      var teamBtn = inDreamteam(sym)
        ? '<button type="button" class="sig-remove" data-remove="' + escapeHtml(sym) + '">Remove</button>'
        : '<button type="button" class="sig-add-row" data-add="' + escapeHtml(sym) + '">Add</button>';
      return (
        '<tr>' +
          '<td class="sig-cell-sym" data-label="Symbol"><a class="sig-sym" href="' + chartLink(sym) + '">' + escapeHtml(sym) + '</a></td>' +
          '<td class="sig-cell-sig" data-label="Signal">' + pill(r.signal) + '</td>' +
          '<td class="sig-cell-1d sig-num' + retClass(r.ret_1d) + '" data-label="1D %">' + escapeHtml(fmtRet(r.ret_1d)) + '</td>' +
          '<td class="sig-cell-ret sig-num' + retClass(r.ret_6m) + '" data-label="6M %">' + escapeHtml(fmtRet(r.ret_6m)) + '</td>' +
          '<td class="sig-cell-px sig-num" data-label="Price">' + escapeHtml(fmtPrice(r.close)) + '</td>' +
          '<td class="sig-cell-sh sig-num" data-label="Shares">' + (size.shares ? String(size.shares) : '—') + '</td>' +
          '<td class="sig-cell-val sig-num" data-label="Value">' + (size.shares ? escapeHtml(fmtMoney(size.value)) : '—') + '</td>' +
          '<td class="sig-cell-chart" data-label=""><a class="sig-btn ghost sig-chart-btn" href="' + chartLink(sym) + '">Chart</a></td>' +
          '<td class="sig-cell-team" data-label="">' + teamBtn + '</td>' +
        '</tr>'
      );
    }).join('');
  }

  function escapeHtml(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function load() {
    state.error = '';
    var view = state.filter || 'dreamteam';
    return api('me/signals', { query: { view: view } }).then(function (data) {
      state.asOf = data.as_of || null;
      state.watchlist = data.watchlist || [];
      state.signals = data.signals || [];
      if (data.counts && typeof data.counts === 'object') {
        state.counts = data.counts;
      }
      render();
    }).catch(function (e) {
      state.error = e.message || 'Could not load signals.';
      render();
    });
  }

  function loadUniverse() {
    return api('universe').then(function (data) {
      state.universe = data.symbols || [];
      var list = document.getElementById('sig-universe');
      if (list) {
        list.innerHTML = state.universe.map(function (s) {
          return '<option value="' + escapeHtml(s) + '">';
        }).join('');
      }
    }).catch(function () {
      /* optional */
    });
  }

  function loadProfile() {
    return api('me/profile').then(function (data) {
      var cap = Number(data && data.capital);
      if (isFinite(cap) && cap > 0) {
        state.capital = cap;
      } else {
        state.capital = 100000;
      }
      render();
    }).catch(function () {
      state.capital = 100000;
      render();
    });
  }

  var capTimer = null;
  function saveCapital(value) {
    var cap = Number(value);
    if (!isFinite(cap)) {
      return;
    }
    if (cap < 1000) {
      cap = 1000;
    }
    if (cap > 100000000) {
      cap = 100000000;
    }
    state.capital = cap;
    render();
    if (capTimer) {
      clearTimeout(capTimer);
    }
    capTimer = setTimeout(function () {
      api('me/profile', {
        method: 'POST',
        body: JSON.stringify({ capital: state.capital })
      }).catch(function (e) {
        state.error = e.message || 'Could not save capital.';
        render();
      });
    }, 400);
  }

  root.addEventListener('click', function (ev) {
    var box = ev.target.closest('.sig-stat[data-filter]');
    if (box) {
      state.filter = box.getAttribute('data-filter') || 'dreamteam';
      load();
      return;
    }
    var rm = ev.target.closest('[data-remove]');
    if (rm) {
      var sym = rm.getAttribute('data-remove');
      rm.disabled = true;
      api('watchlist/' + encodeURIComponent(sym), { method: 'DELETE' }).then(function (data) {
        state.watchlist = data.watchlist || state.watchlist.filter(function (s) {
          return s !== sym;
        });
        return load();
      }).catch(function (e) {
        state.error = e.message;
        render();
      });
      return;
    }
    var sortBtn = ev.target.closest('[data-sort]');
    if (sortBtn) {
      ev.preventDefault();
      toggleSort(sortBtn.getAttribute('data-sort'));
      return;
    }
    var addBtn = ev.target.closest('[data-add]');
    if (addBtn) {
      var addSym = addBtn.getAttribute('data-add');
      addBtn.disabled = true;
      api('watchlist', {
        method: 'POST',
        body: JSON.stringify({ symbol: addSym })
      }).then(function () {
        return load();
      }).catch(function (e) {
        state.error = e.message;
        render();
      });
    }
  });

  root.addEventListener('input', function (ev) {
    var q = ev.target.closest('[data-q]');
    if (q) {
      state.q = q.value || '';
      render();
      return;
    }
    var cap = ev.target.closest('[data-capital]');
    if (!cap) {
      return;
    }
    saveCapital(cap.value);
  });

  root.addEventListener('change', function (ev) {
    var cap = ev.target.closest('[data-capital]');
    if (!cap) {
      return;
    }
    saveCapital(cap.value);
  });

  var form = root.querySelector('[data-add-form]');
  if (form) {
    form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var input = form.querySelector('input[name="symbol"]');
      var symbol = (input && input.value || '').trim().toUpperCase();
      if (!symbol) {
        return;
      }
      api('watchlist', {
        method: 'POST',
        body: JSON.stringify({ symbol: symbol })
      }).then(function () {
        if (input) {
          input.value = '';
        }
        return load();
      }).catch(function (e) {
        state.error = e.message;
        render();
      });
    });
  }

  function addMany(list) {
    var i = 0;
    function next() {
      if (i >= list.length) {
        return load();
      }
      var symbol = list[i++];
      return api('watchlist', {
        method: 'POST',
        body: JSON.stringify({ symbol: symbol })
      }).then(next).catch(function (e) {
        state.error = e.message;
        render();
        return next();
      });
    }
    return next();
  }

  root.addEventListener('click', function (ev) {
    var pack = ev.target.closest('[data-pack]');
    if (!pack) {
      return;
    }
    ev.preventDefault();
    var list = (pack.getAttribute('data-pack') || '').split(',').map(function (s) {
      return s.trim();
    }).filter(Boolean);
    pack.disabled = true;
    addMany(list).then(function () {
      pack.disabled = false;
    });
  }, true);

  loadProfile();
  load();
  loadUniverse();
})();
