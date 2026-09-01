(function () {
  'use strict';

  var cfg = window.SIG || {};
  var el = document.getElementById('sig-chart');
  var status = document.getElementById('sig-chart-status');
  var title = document.getElementById('sig-chart-symbol');
  var page = document.getElementById('sig-chart-page');
  var rest = cfg.rest || '';
  var nonce = cfg.nonce || '';
  var chartUrl = cfg.chartUrl || '/?pagename=chart';

  var params = new URLSearchParams(window.location.search);
  var symbol = (params.get('symbol') || cfg.symbol || '').toUpperCase().trim();
  var state = {
    symbol: symbol,
    mode: 'line',
    payload: null,
    chart: null,
    priceSeries: null,
    emaSeries: []
  };

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

  function api(path) {
    return fetch(rest + path, {
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
          throw new Error((body && body.message) || ('HTTP ' + res.status));
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

  function assignSymbol(sym) {
    sym = String(sym || '').toUpperCase().trim();
    if (!sym) {
      return;
    }
    var join = chartUrl.indexOf('?') >= 0 ? '&' : '?';
    window.location.assign(chartUrl + join + 'symbol=' + encodeURIComponent(sym));
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
        return { priceRange: { minValue: 0, maxValue: 100 } };
      }
    }, 1);
    series.setData(data);
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
    setStatus('Loading ' + state.symbol + '…');
    api('bars/' + encodeURIComponent(state.symbol)).then(function (payload) {
      state.payload = payload;
      renderChart();
    }).catch(function (e) {
      setStatus(e.message || 'Could not load chart.', true);
    });
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
      var btn = ev.target.closest('[data-mode]');
      if (!btn) {
        return;
      }
      setMode(btn.getAttribute('data-mode'));
    });
  }

  document.addEventListener('sig-theme', function () {
    if (state.payload) {
      renderChart();
    }
  });

  syncModeButtons();
  loadUniverse();

  if (!state.symbol) {
    setStatus('Pick a symbol to chart.');
  } else {
    loadBars();
  }

  window.addEventListener('resize', function () {
    if (state.chart && el) {
      state.chart.applyOptions({ width: el.clientWidth });
    }
  });
})();
