(function () {
  var d = window.METEO_DATA;
  if (!d || !window.Chart) return;

  var STORAGE_KEY = 'meteo13_chart_density_mode';
  var DENSITY_ORDER = ['auto', 'compact', 'dense'];
  var dToggle = document.getElementById('chartDensityToggle');

  var labels = Array.isArray(d.labels) ? d.labels : [];
  var l = d.chart_labels || {};
  var ui = d.chart_ui || {};
  var major = parseInt((String(window.Chart.version || '3').split('.')[0] || '3'), 10);
  var isV2 = Number.isFinite(major) && major < 3;
  var charts = [];
  var resizeTimer = null;

  function safeNumber(v) {
    if (v === null || v === undefined || v === '') return null;
    var n = Number(v);
    return Number.isFinite(n) ? n : null;
  }

  function compactDateLabel(raw) {
    if (typeof raw !== 'string') return String(raw || '');
    if (raw.length >= 16) {
      var date = raw.slice(5, 10);
      var time = raw.slice(11, 16);
      return date + ' ' + time;
    }
    return raw;
  }

  function normalizeMode(mode) {
    return DENSITY_ORDER.indexOf(mode) >= 0 ? mode : 'auto';
  }

  function readMode() {
    try {
      return normalizeMode(window.localStorage.getItem(STORAGE_KEY) || 'auto');
    } catch (_e) {
      return 'auto';
    }
  }

  function writeMode(mode) {
    try {
      window.localStorage.setItem(STORAGE_KEY, normalizeMode(mode));
    } catch (_e) {
      // No-op when localStorage is blocked.
    }
  }

  function modeLabel(mode) {
    if (mode === 'compact') return ui.density_compact || 'Compact';
    if (mode === 'dense') return ui.density_dense || 'Dense';
    return ui.density_auto || 'Auto';
  }

  function resolveMode(selectedMode) {
    if (selectedMode !== 'auto') return selectedMode;
    return window.matchMedia('(max-width: 820px)').matches ? 'compact' : 'dense';
  }

  function chooseMaxXTicks(count, resolvedMode) {
    if (resolvedMode === 'compact') {
      if (count <= 24) return 8;
      if (count <= 72) return 6;
      if (count <= 240) return 5;
      return 4;
    }

    if (count <= 24) return 16;
    if (count <= 72) return 12;
    if (count <= 240) return 10;
    return 8;
  }

  function chooseMaxYTicks(resolvedMode) {
    return resolvedMode === 'compact' ? 5 : 9;
  }

  function commonDataset(label, color, data) {
    return {
      label: label,
      data: data,
      borderColor: color,
      backgroundColor: color,
      borderWidth: 2,
      pointRadius: 0,
      pointHoverRadius: 3,
      fill: false,
      tension: 0.22,
      spanGaps: true
    };
  }

  function buildScales(resolvedMode) {
    var maxXTicks = chooseMaxXTicks(labels.length, resolvedMode);
    var maxYTicks = chooseMaxYTicks(resolvedMode);

    if (isV2) {
      return {
        xAxes: [{
          gridLines: { color: 'rgba(120,140,165,0.20)' },
          ticks: {
            maxTicksLimit: maxXTicks,
            callback: function (v) { return compactDateLabel(v); }
          }
        }],
        yAxes: [{
          gridLines: { color: 'rgba(120,140,165,0.22)' },
          ticks: { beginAtZero: false, maxTicksLimit: maxYTicks }
        }]
      };
    }

    return {
      x: {
        grid: { color: 'rgba(120,140,165,0.20)' },
        ticks: {
          maxTicksLimit: maxXTicks,
          callback: function (_value, index) {
            return compactDateLabel(labels[index]);
          }
        }
      },
      y: {
        grid: { color: 'rgba(120,140,165,0.22)' },
        ticks: { maxTicksLimit: maxYTicks }
      }
    };
  }

  function buildOptions(resolvedMode) {
    var scales = buildScales(resolvedMode);

    if (isV2) {
      return {
        responsive: true,
        maintainAspectRatio: false,
        animation: { duration: 0 },
        legend: { display: true, position: 'top' },
        tooltips: { mode: 'index', intersect: false },
        hover: { mode: 'index', intersect: false },
        scales: scales
      };
    }

    return {
      responsive: true,
      maintainAspectRatio: false,
      animation: false,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: { display: true, position: 'top' },
        tooltip: { mode: 'index', intersect: false }
      },
      scales: scales
    };
  }

  function buildChart(id, datasetLabel, color, series, resolvedMode) {
    var cv = document.getElementById(id);
    if (!cv) return;

    var values = (Array.isArray(series) ? series : []).map(safeNumber);
    var chart = new Chart(cv.getContext('2d'), {
      type: 'line',
      data: {
        labels: labels,
        datasets: [commonDataset(datasetLabel, color, values)]
      },
      options: buildOptions(resolvedMode)
    });

    charts.push(chart);
  }

  function rainSeries() {
    var rr = Array.isArray(d.RR) ? d.RR : [];
    var day = Array.isArray(d.R) ? d.R : [];
    var len = Math.max(rr.length, day.length);
    var out = [];

    for (var i = 0; i < len; i++) {
      var rrVal = safeNumber(rr[i]);
      var dayVal = safeNumber(day[i]);
      if (rrVal === null && dayVal === null) {
        out.push(null);
      } else {
        out.push((rrVal || 0) + (dayVal || 0));
      }
    }

    return out;
  }

  function windSeries() {
    var w = Array.isArray(d.W) ? d.W : [];
    var g = Array.isArray(d.G) ? d.G : [];
    var len = Math.max(w.length, g.length);
    var out = [];

    for (var i = 0; i < len; i++) {
      var wVal = safeNumber(w[i]);
      var gVal = safeNumber(g[i]);
      if (wVal === null && gVal === null) {
        out.push(null);
      } else {
        out.push(Math.max(wVal || 0, gVal || 0));
      }
    }

    return out;
  }

  function destroyCharts() {
    for (var i = 0; i < charts.length; i++) {
      if (charts[i] && typeof charts[i].destroy === 'function') {
        charts[i].destroy();
      }
    }
    charts = [];
  }

  function updateToggleLabel(selectedMode) {
    if (!dToggle) return;
    var prefix = ui.density_label || 'Density';
    dToggle.textContent = prefix + ': ' + modeLabel(selectedMode);
  }

  function renderAll(selectedMode) {
    var resolved = resolveMode(selectedMode);
    destroyCharts();

    buildChart('chartT', l.T || 'Temperature', '#d04f2b', d.T, resolved);
    buildChart('chartH', l.H || 'Humidity', '#0f9d58', d.H, resolved);
    buildChart('chartP', l.P || 'Pressure', '#0f6cbf', d.P, resolved);
    buildChart('chartR', l.R || 'Rain', '#1269a8', rainSeries(), resolved);
    buildChart('chartW', l.W || 'Wind', '#7e3fa1', windSeries(), resolved);
    updateToggleLabel(selectedMode);
  }

  var selectedMode = readMode();
  renderAll(selectedMode);

  if (dToggle) {
    dToggle.addEventListener('click', function () {
      var idx = DENSITY_ORDER.indexOf(selectedMode);
      selectedMode = DENSITY_ORDER[(idx + 1) % DENSITY_ORDER.length];
      writeMode(selectedMode);
      renderAll(selectedMode);
    });
  }

  window.addEventListener('resize', function () {
    if (selectedMode !== 'auto') return;
    window.clearTimeout(resizeTimer);
    resizeTimer = window.setTimeout(function () {
      renderAll(selectedMode);
    }, 180);
  });
})();
