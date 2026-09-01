(function () {
  'use strict';

  var cfg = window.SIG || {};
  var el = document.getElementById('sig-chart');
  var status = document.getElementById('sig-chart-status');
  var title = document.getElementById('sig-chart-symbol');
  var page = document.getElementById('sig-chart-page');
  var listEl = document.getElementById('sig-chart-list');
  var rest = cfg.rest || '';
  var nonce = cfg.nonce || '';
  var chartUrl = cfg.chartUrl || '/?pagename=chart';
  var dashUrl = cfg.dashUrl || '/?pagename=dashboard';
  var LIST_KEY = 'sig-chart-list';
  var LIST_VIEWS = { dreamteam: 1, buy: 1, sell: 1, watch: 1 };

  var params = new URLSearchParams(window.location.search);
  var symbol = (params.get('symbol') || cfg.symbol || '').toUpperCase().trim();
  var state = {
    symbol: symbol,
    mode: 'line',
    payload: null,
    chart: null,
    priceSeries: null,
    emaSeries: [],
    list: 'dreamteam',
    rows: [],
    paidLists: !(cfg.isPaid === false || cfg.isPaid === 0 || cfg.isPaid === '0' || cfg.isPaid === ''),
    loadGen: 0
  };
  var barCache = {};
  var barPending = {};
  var listCache = {};

  function escapeHtml(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function setStatus(msg, isError) {
    if (!status) {
      return;
    }
    status.textContent = msg || '';
    status.classList.toggle('sig-error', !!isError);
  }

  function api(path, query) {
    var url = rest + path;
    if (query && typeof query === 'object') {
      var qs = Object.keys(query).map(function (k) {
        return encodeURIComponent(k) + '=' + encodeURIComponent(query[k]);
      }).join('&');
      if (qs) {
        url += (url.indexOf('?') >= 0 ? '&' : '?') + qs;
      }
    }
    return fetch(url, {
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        'X-WP-Nonce': nonce
      }
    }).then(function (res) {
      return res.json().catch(function () {
        return {};
      }).then(function (body) {
        if (!res.ok) {
          var err = new Error((body && body.message) || ('HTTP ' + res.status));
          err.status = res.status;
          throw err;
        }
        return body;
      });
    });
  }

  function loadUniverse() {
    var list = document.getElementById('sig-universe');
    return api('universe').then(function (data) {
      var symbols = data.symbols || [];
      if (list) {
        list.innerHTML = symbols.map(function (s) {
          return '<option value="' + escapeHtml(s) + '">';
        }).join('');
      }
    }).catch(function () {
      /* optional */
    });
  }

  function savedList() {
    var v = 'dreamteam';
    try {
      v = localStorage.getItem(LIST_KEY) || 'dreamteam';
    } catch (err) {
      v = 'dreamteam';
    }
    if (!LIST_VIEWS[v]) {
      v = 'dreamteam';
    }
    if (!state.paidLists && v !== 'dreamteam') {
      v = 'dreamteam';
    }
    return v;
  }

  function persistList(view) {
    try {
      localStorage.setItem(LIST_KEY, view);
    } catch (err) {
      /* ignore */
    }
  }

  function updateUrl(sym) {
    var url = new URL(window.location.href);
    if (sym) {
      url.searchParams.set('symbol', sym);
    } else {
      url.searchParams.delete('symbol');
    }
    history.replaceState({ symbol: sym }, '', url.pathname + url.search + url.hash);
  }

  function closeDrawer() {
    if (!page) {
      return;
    }
    page.classList.remove('is-drawer-open');
    var backdrop = page.querySelector('.sig-chart-drawer-backdrop');
    if (backdrop) {
      backdrop.hidden = true;
    }
  }

  function openDrawer() {
    if (!page) {
      return;
    }
    page.classList.add('is-drawer-open');
    var backdrop = page.querySelector('.sig-chart-drawer-backdrop');
    if (backdrop) {
      backdrop.hidden = false;
    }
  }

  function fetchBars(sym) {
    sym = String(sym || '').toUpperCase().trim();
    if (!sym) {
      return Promise.reject(new Error('Missing symbol'));
    }
    if (barCache[sym]) {
      return Promise.resolve(barCache[sym]);
    }
    if (barPending[sym]) {
      return barPending[sym];
    }
    barPending[sym] = api('bars/' + encodeURIComponent(sym)).then(function (payload) {
      barCache[sym] = payload;
      delete barPending[sym];
      return payload;
    }).catch(function (err) {
      delete barPending[sym];
      throw err;
    });
    return barPending[sym];
  }

  function prefetchNeighbors() {
    var rows = state.rows || [];
    if (!rows.length || !state.symbol) {
      return;
    }
    var cur = String(state.symbol).toUpperCase();
    var i = -1;
    var n;
    for (n = 0; n < rows.length; n++) {
      if (String(rows[n].symbol || '').toUpperCase() === cur) {
        i = n;
        break;
      }
    }
    if (i < 0) {
      return;
    }
    var around = [
      rows[(i + 1) % rows.length],
      rows[(i - 1 + rows.length) % rows.length]
    ];
    around.forEach(function (row) {
      var s = row && row.symbol ? String(row.symbol).toUpperCase() : '';
      if (s && s !== cur && !barCache[s] && !barPending[s]) {
        fetchBars(s).catch(function () {
          /* prefetch is best-effort */
        });
      }
    });
  }

  function assignSymbol(sym) {
    sym = String(sym || '').toUpperCase().trim();
    if (!sym) {
      return;
    }
    closeDrawer();
    if (sym === state.symbol && state.payload && state.payload.symbol === sym) {
      highlightCurrent();
      return;
    }
    state.symbol = sym;
    if (title) {
      title.textContent = sym;
    }
    var pickInput = page ? page.querySelector('[data-chart-pick] input[name="symbol"]') : null;
    if (pickInput) {
      pickInput.value = sym;
    }
    updateUrl(sym);
    highlightCurrent();
    loadBars();
  }

  function zoomSixMonths(chart, candles) {
    if (!chart || !candles || !candles.length) {
      return;
    }
    var last = candles[candles.length - 1].time;
    var parts = String(last).split('-');
    if (parts.length !== 3) {
      chart.timeScale().fitContent();
      return;
    }
    var dt = new Date(Date.UTC(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2])));
    dt.setUTCDate(dt.getUTCDate() - 183);
    var y = dt.getUTCFullYear();
    var m = String(dt.getUTCMonth() + 1);
    var d = String(dt.getUTCDate());
    if (m.length < 2) {
      m = '0' + m;
    }
    if (d.length < 2) {
      d = '0' + d;
    }
    var from = y + '-' + m + '-' + d;
    try {
      chart.timeScale().setVisibleRange({ from: from, to: last });
    } catch (err) {
      chart.timeScale().fitContent();
    }
  }

  function addPriceSeries(chart, mode, candles) {
    var series;
    if (mode === 'candles') {
      series = chart.addSeries(LightweightCharts.CandlestickSeries, {
        upColor: '#22c55e',
        downColor: '#ef4444',
        borderUpColor: '#22c55e',
        borderDownColor: '#ef4444',
        wickUpColor: '#86efac',
        wickDownColor: '#fca5a5'
      });
      series.setData((candles || []).map(function (c) {
        return {
          time: c.time,
          open: c.open,
          high: c.high,
          low: c.low,
          close: c.close
        };
      }));
    } else {
      series = chart.addSeries(LightweightCharts.LineSeries, {
        color: '#38bdf8',
        lineWidth: 2,
        priceLineVisible: true,
        lastValueVisible: true
      });
      series.setData((candles || []).map(function (c) {
        return { time: c.time, value: c.close };
      }));
    }
    return series;
  }

  function addEmaRibbon(chart, ema) {
    var colors = {
      15: { color: '#22c55e', width: 2 },
      25: { color: '#64748b', width: 1 },
      36: { color: '#64748b', width: 1 },
      45: { color: '#64748b', width: 1 },
      55: { color: '#64748b', width: 1 },
      65: { color: '#ef4444', width: 2 }
    };
    var seriesList = [];
    Object.keys(colors).forEach(function (p) {
      var series = chart.addSeries(LightweightCharts.LineSeries, {
        color: colors[p].color,
        lineWidth: colors[p].width,
        priceLineVisible: false,
        lastValueVisible: p === '15' || p === '65'
      });
      series.setData(ema[p] || ema[Number(p)] || []);
      seriesList.push(series);
    });
    return seriesList;
  }

  function rsiData(candles, period) {
    period = period || 14;
    var out = [];
    if (!candles || candles.length <= period) {
      return out;
    }
    var i;
    var avgGain = 0;
    var avgLoss = 0;
    for (i = 1; i <= period; i++) {
      var delta = candles[i].close - candles[i - 1].close;
      if (delta >= 0) {
        avgGain += delta;
      } else {
        avgLoss -= delta;
      }
    }
    avgGain /= period;
    avgLoss /= period;
    function valueOf(gain, loss) {
      if (loss === 0) {
        return 100;
      }
      var rs = gain / loss;
      return 100 - (100 / (1 + rs));
    }
    out.push({ time: candles[period].time, value: valueOf(avgGain, avgLoss) });
    for (i = period + 1; i < candles.length; i++) {
      var ch = candles[i].close - candles[i - 1].close;
      var gain = ch > 0 ? ch : 0;
      var loss = ch < 0 ? -ch : 0;
      avgGain = ((avgGain * (period - 1)) + gain) / period;
      avgLoss = ((avgLoss * (period - 1)) + loss) / period;
      out.push({ time: candles[i].time, value: valueOf(avgGain, avgLoss) });
    }
    return out;
  }

  function addRsiPane(chart, candles) {
    var data = rsiData(candles, 14);
    if (!data.length) {
      return null;
    }
    var dash = 2;
    if (LightweightCharts.LineStyle && LightweightCharts.LineStyle.Dashed != null) {
      dash = LightweightCharts.LineStyle.Dashed;
    }
    var series = chart.addSeries(LightweightCharts.LineSeries, {
      color: '#c084fc',
      lineWidth: 2,
      priceLineVisible: false,
      lastValueVisible: true,
      priceFormat: { type: 'price', precision: 1, minMove: 0.1 },
      autoscaleInfoProvider: function () {
        return {
          priceRange: { minValue: 0, maxValue: 100 },
          margins: { above: 0, below: 0 }
        };
      }
    }, 1);
    series.setData(data);
    try {
      series.priceScale().applyOptions({
        autoScale: true,
        scaleMargins: { top: 0, bottom: 0 }
      });
    } catch (err) {
      /* price scale options are optional */
    }
    series.createPriceLine({
      price: 70,
      color: 'rgba(239, 68, 68, 0.55)',
      lineWidth: 1,
      lineStyle: dash,
      axisLabelVisible: true,
      title: '70'
    });
    series.createPriceLine({
      price: 30,
      color: 'rgba(34, 197, 94, 0.55)',
      lineWidth: 1,
      lineStyle: dash,
      axisLabelVisible: true,
      title: '30'
    });
    try {
      var panes = chart.panes();
      if (panes && panes[0] && typeof panes[0].setStretchFactor === 'function') {
        panes[0].setStretchFactor(3);
      }
      if (panes && panes[1] && typeof panes[1].setStretchFactor === 'function') {
        panes[1].setStretchFactor(1);
      }
    } catch (err) {
      /* pane sizing is optional */
    }
    return series;
  }

  function syncModeButtons() {
    if (!page) {
      return;
    }
    page.querySelectorAll('[data-mode]').forEach(function (btn) {
      btn.classList.toggle('is-on', btn.getAttribute('data-mode') === state.mode);
    });
  }

  function renderChart() {
    var payload = state.payload;
    if (!el) {
      return;
    }
    if (typeof LightweightCharts === 'undefined') {
      setStatus('Chart library failed to load.', true);
      return;
    }
    if (!payload) {
      return;
    }
    var candles = payload.candles || [];
    if (!candles.length) {
      setStatus('No bars for ' + (payload.symbol || state.symbol), true);
      return;
    }

    if (state.chart) {
      state.chart.remove();
      state.chart = null;
      state.priceSeries = null;
      state.emaSeries = [];
    }

    function cssVar(name, fallback) {
      var v = getComputedStyle(document.documentElement).getPropertyValue(name).trim();
      return v || fallback;
    }
    var height = Math.max(el.clientHeight || 0, 680);
    var chart = LightweightCharts.createChart(el, {
      width: el.clientWidth,
      height: height,
      layout: {
        background: { color: cssVar('--sig-bg', '#0b1220') },
        textColor: cssVar('--sig-muted', '#94a3b8'),
        fontFamily: 'IBM Plex Sans, Segoe UI, sans-serif'
      },
      grid: {
        vertLines: { color: cssVar('--sig-chart-grid', '#1e293b') },
        horzLines: { color: cssVar('--sig-chart-grid', '#1e293b') }
      },
      rightPriceScale: { borderColor: cssVar('--sig-border', '#243049') },
      timeScale: {
        borderColor: cssVar('--sig-border', '#243049'),
        timeVisible: false
      },
      crosshair: { mode: 0 }
    });

    state.chart = chart;
    state.priceSeries = addPriceSeries(chart, state.mode, candles);
    state.emaSeries = addEmaRibbon(chart, payload.ema || {});
    addRsiPane(chart, candles);
    zoomSixMonths(chart, candles);
    setStatus('');
  }

  function setMode(mode) {
    state.mode = mode === 'candles' ? 'candles' : 'line';
    syncModeButtons();
    if (!state.chart || !state.payload) {
      return;
    }
    if (typeof LightweightCharts === 'undefined') {
      return;
    }
    if (state.priceSeries) {
      try {
        state.chart.removeSeries(state.priceSeries);
      } catch (err) {
        renderChart();
        return;
      }
    }
    state.priceSeries = addPriceSeries(state.chart, state.mode, state.payload.candles || []);
  }

  function loadBars() {
    if (!state.symbol) {
      setStatus('Pick a symbol to chart.');
      return;
    }
    if (typeof LightweightCharts === 'undefined') {
      setStatus('Chart library failed to load.', true);
      return;
    }
    var gen = ++state.loadGen;
    var sym = state.symbol;
    setStatus('Loading ' + sym + '…');
    fetchBars(sym).then(function (payload) {
      if (gen !== state.loadGen) {
        return;
      }
      state.payload = payload;
      renderChart();
      prefetchNeighbors();
    }).catch(function (e) {
      if (gen !== state.loadGen) {
        return;
      }
      setStatus(e.message || 'Could not load chart.', true);
    });
  }

  function watchlistRows(data) {
    return (data.watchlist || []).map(function (s) {
      return {
        symbol: String(s || '').toUpperCase(),
        signal: 'none',
        under_redline: false
      };
    }).filter(function (r) {
      return !!r.symbol;
    });
  }

  function fetchWatchlistFallback() {
    if (listCache.dreamteam) {
      return Promise.resolve(listCache.dreamteam);
    }
    return api('watchlist').then(function (data) {
      var rows = watchlistRows(data);
      listCache.dreamteam = rows;
      return rows;
    }).catch(function () {
      listCache.dreamteam = [];
      return [];
    });
  }

  function disablePaidLists() {
    state.paidLists = false;
    if (state.list !== 'dreamteam') {
      state.list = 'dreamteam';
      persistList('dreamteam');
    }
    syncListTabs();
  }

  function fetchList(view) {
    if (!LIST_VIEWS[view]) {
      view = 'dreamteam';
    }
    if (listCache[view]) {
      return Promise.resolve(listCache[view]);
    }
    if (!state.paidLists) {
      return fetchWatchlistFallback();
    }
    return api('me/signals', { view: view }).then(function (data) {
      state.paidLists = true;
      var rows = data.signals || [];
      listCache[view] = rows;
      return rows;
    }).catch(function (err) {
      if (err && (err.status === 401 || err.status === 403)) {
        disablePaidLists();
        return fetchWatchlistFallback();
      }
      throw err;
    });
  }

  function rowPills(row) {
    var s = String(row.signal || 'none').toLowerCase();
    var html = '';
    if (s === 'buy' || s === 'sell' || s === 'watch') {
      html += '<span class="sig-pill ' + s + '">' + escapeHtml(s) + '</span>';
    }
    if (row.under_redline) {
      html += '<span class="sig-pill under">UNDER</span>';
    }
    return html;
  }

  function emptyHtml() {
    if (state.list === 'dreamteam') {
      return '<p class="sig-chart-empty">No symbols in Dreamteam. Add them on the <a href="' + escapeHtml(dashUrl) + '">dashboard</a>.</p>';
    }
    return '<p class="sig-chart-empty">No signals today.</p>';
  }

  function highlightCurrent() {
    if (!listEl) {
      return;
    }
    var cur = String(state.symbol || '').toUpperCase();
    listEl.querySelectorAll('[data-sym]').forEach(function (row) {
      var on = row.getAttribute('data-sym') === cur;
      row.classList.toggle('is-current', on);
      row.setAttribute('aria-selected', on ? 'true' : 'false');
      if (on && typeof row.scrollIntoView === 'function') {
        try {
          row.scrollIntoView({ block: 'nearest' });
        } catch (err) {
          row.scrollIntoView();
        }
      }
    });
  }

  function renderList() {
    if (!listEl) {
      return;
    }
    var rows = state.rows || [];
    if (!rows.length) {
      listEl.innerHTML = emptyHtml();
      return;
    }
    listEl.innerHTML = rows.map(function (row) {
      var sym = String(row.symbol || '').toUpperCase();
      return '<button type="button" class="sig-chart-row" role="option" data-sym="' + escapeHtml(sym) + '" aria-selected="false">' +
        '<span class="sig-sym">' + escapeHtml(sym) + '</span>' +
        '<span class="sig-chart-row-pills">' + rowPills(row) + '</span>' +
        '</button>';
    }).join('');
    highlightCurrent();
  }

  function syncListTabs() {
    if (!page) {
      return;
    }
    page.querySelectorAll('[data-paid-list]').forEach(function (btn) {
      btn.hidden = !state.paidLists;
    });
    page.querySelectorAll('[data-list]').forEach(function (btn) {
      var view = btn.getAttribute('data-list');
      btn.classList.toggle('is-on', view === state.list);
      btn.setAttribute('aria-selected', view === state.list ? 'true' : 'false');
    });
  }

  function setList(view) {
    if (!LIST_VIEWS[view]) {
      view = 'dreamteam';
    }
    if (!state.paidLists) {
      view = 'dreamteam';
    }
    state.list = view;
    persistList(view);
    syncListTabs();
    return fetchList(view).then(function (rows) {
      state.rows = rows || [];
      renderList();
      prefetchNeighbors();
    }).catch(function () {
      state.rows = [];
      renderList();
    });
  }

  function cycleList(dir) {
    var rows = state.rows || [];
    if (!rows.length) {
      return;
    }
    var cur = String(state.symbol || '').toUpperCase();
    var i = -1;
    var n;
    for (n = 0; n < rows.length; n++) {
      if (String(rows[n].symbol || '').toUpperCase() === cur) {
        i = n;
        break;
      }
    }
    var next;
    if (i < 0) {
      next = dir > 0 ? 0 : rows.length - 1;
    } else {
      next = (i + dir + rows.length) % rows.length;
    }
    assignSymbol(rows[next].symbol);
  }

  function isTypingTarget(target) {
    if (!target) {
      return false;
    }
    if (target.isContentEditable) {
      return true;
    }
    var tag = (target.tagName || '').toLowerCase();
    return tag === 'input' || tag === 'textarea' || tag === 'select';
  }

  if (title && state.symbol) {
    title.textContent = state.symbol;
  }

  var pickForm = page ? page.querySelector('[data-chart-pick]') : null;
  if (pickForm) {
    var pickInput = pickForm.querySelector('input[name="symbol"]');
    if (pickInput && state.symbol) {
      pickInput.value = state.symbol;
    }
    pickForm.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var input = pickForm.querySelector('input[name="symbol"]');
      assignSymbol(input && input.value);
    });
  }

  if (page) {
    page.addEventListener('click', function (ev) {
      var modeBtn = ev.target.closest('[data-mode]');
      if (modeBtn) {
        setMode(modeBtn.getAttribute('data-mode'));
        return;
      }
      var listBtn = ev.target.closest('[data-list]');
      if (listBtn) {
        setList(listBtn.getAttribute('data-list'));
        return;
      }
      var row = ev.target.closest('[data-sym]');
      if (row) {
        assignSymbol(row.getAttribute('data-sym'));
        return;
      }
      if (ev.target.closest('[data-drawer-open]')) {
        openDrawer();
        return;
      }
      if (ev.target.closest('[data-drawer-close]')) {
        closeDrawer();
      }
    });
  }

  document.addEventListener('keydown', function (ev) {
    if (isTypingTarget(ev.target)) {
      return;
    }
    if (ev.metaKey || ev.ctrlKey || ev.altKey) {
      return;
    }
    var key = ev.key;
    if (key === 'Escape' && page && page.classList.contains('is-drawer-open')) {
      closeDrawer();
      return;
    }
    if (key === 'ArrowUp' || key === 'k' || key === 'K') {
      ev.preventDefault();
      cycleList(-1);
      return;
    }
    if (key === 'ArrowDown' || key === 'j' || key === 'J') {
      ev.preventDefault();
      cycleList(1);
    }
  });

  document.addEventListener('sig-theme', function () {
    if (state.payload) {
      renderChart();
    }
  });

  syncModeButtons();
  loadUniverse();
  if (!state.paidLists) {
    syncListTabs();
  }
  setList(savedList());

  if (!state.symbol) {
    setStatus('Pick a symbol to chart.');
  } else {
    loadBars();
  }

  window.addEventListener('resize', function () {
    if (state.chart && el) {
      state.chart.applyOptions({ width: el.clientWidth });
    }
    if (window.innerWidth >= 720) {
      closeDrawer();
    }
  });
})();
