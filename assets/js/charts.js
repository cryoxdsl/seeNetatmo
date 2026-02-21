(function () {
  var d = window.METEO_DATA;
  if (!d) return;

  var STORAGE_KEY = 'meteo13_chart_density_mode';
  var DENSITY_ORDER = ['auto', 'compact', 'dense'];
  var dToggle = document.getElementById('chartDensityToggle');
  var labels = Array.isArray(d.labels) ? d.labels : [];
  var l = d.chart_labels || {};
  var ui = d.chart_ui || {};
  var charts = [];
  var resizeTimer = null;

  function safeNumber(v) {
    if (v === null || v === undefined || v === '') return null;
    var n = Number(v);
    return Number.isFinite(n) ? n : null;
  }

  function rawDateLabel(raw) {
    if (typeof raw !== 'string') return String(raw || '');
    if (raw.length >= 16) {
      return raw.slice(8, 10) + '/' + raw.slice(5, 7) + ' ' + raw.slice(11, 16);
    }
    return raw;
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
      if (count <= 24) return 6;
      if (count <= 72) return 5;
      if (count <= 240) return 4;
      return 3;
    }
    if (count <= 24) return 12;
    if (count <= 72) return 10;
    if (count <= 240) return 8;
    return 6;
  }

  function chooseYTicks(resolvedMode) {
    return resolvedMode === 'compact' ? 4 : 6;
  }

  function valueDecimals(values) {
    var max = 0;
    for (var i = 0; i < values.length; i++) {
      var v = values[i];
      if (!Number.isFinite(v)) continue;
      var s = String(v);
      var p = s.indexOf('.');
      if (p >= 0) max = Math.max(max, s.length - p - 1);
    }
    return Math.min(2, max);
  }

  function formatValue(value, decimals) {
    if (!Number.isFinite(value)) return 'N/A';
    return Number(value).toFixed(decimals);
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

  function createTooltip(container) {
    var tip = document.createElement('div');
    tip.className = 'chart-tooltip';
    tip.style.display = 'none';
    container.style.position = container.style.position || 'relative';
    container.appendChild(tip);
    return tip;
  }

  function CanvasLineChart(canvas, config) {
    this.canvas = canvas;
    this.ctx = canvas.getContext('2d');
    this.container = canvas.closest('.chart-panel') || canvas.parentElement;
    this.tooltip = createTooltip(this.container);
    this.cfg = config;
    this.hoverIndex = null;
    this.dpr = Math.max(1, window.devicePixelRatio || 1);
    this.area = { left: 58, right: 10, top: 16, bottom: 56 };
    this._bind();
    this.resize();
  }

  CanvasLineChart.prototype._bind = function () {
    var self = this;
    this.canvas.addEventListener('mousemove', function (e) { self.onMove(e); });
    this.canvas.addEventListener('mouseleave', function () { self.onLeave(); });
    this.canvas.addEventListener('touchstart', function (e) { self.onTouch(e); }, { passive: true });
    this.canvas.addEventListener('touchmove', function (e) { self.onTouch(e); }, { passive: true });
    this.canvas.addEventListener('touchend', function () { self.onLeave(); }, { passive: true });
  };

  CanvasLineChart.prototype.resize = function () {
    var rect = this.canvas.getBoundingClientRect();
    var w = Math.max(300, Math.round(rect.width));
    var h = Math.max(220, Math.round(rect.height));
    this.canvas.width = Math.round(w * this.dpr);
    this.canvas.height = Math.round(h * this.dpr);
    this.ctx.setTransform(this.dpr, 0, 0, this.dpr, 0, 0);
    this.w = w;
    this.h = h;
    this.draw();
  };

  CanvasLineChart.prototype.setMode = function (mode) {
    this.cfg.mode = mode;
    this.draw();
  };

  CanvasLineChart.prototype.getPlot = function () {
    return {
      left: this.area.left,
      top: this.area.top,
      right: this.w - this.area.right,
      bottom: this.h - this.area.bottom,
      width: this.w - this.area.left - this.area.right,
      height: this.h - this.area.top - this.area.bottom
    };
  };

  CanvasLineChart.prototype.yRange = function () {
    if (Number.isFinite(this.cfg.yMin) && Number.isFinite(this.cfg.yMax) && this.cfg.yMax > this.cfg.yMin) {
      return { min: this.cfg.yMin, max: this.cfg.yMax };
    }

    var data = this.cfg.values;
    var min = Infinity;
    var max = -Infinity;
    for (var i = 0; i < data.length; i++) {
      var v = data[i];
      if (!Number.isFinite(v)) continue;
      min = Math.min(min, v);
      max = Math.max(max, v);
    }
    if (!Number.isFinite(min) || !Number.isFinite(max)) return { min: 0, max: 1 };
    if (min === max) {
      min -= 1;
      max += 1;
    }
    var pad = (max - min) * 0.08;
    return { min: min - pad, max: max + pad };
  };

  CanvasLineChart.prototype.xForIndex = function (idx, plot) {
    if (labels.length <= 1) return plot.left;
    return plot.left + (idx * plot.width) / (labels.length - 1);
  };

  CanvasLineChart.prototype.yForValue = function (v, yr, plot) {
    return plot.bottom - ((v - yr.min) * plot.height) / (yr.max - yr.min);
  };

  CanvasLineChart.prototype.drawWatermark = function (plot) {
    var text = (ui.watermark || '').trim();
    if (!text) return;

    var ctx = this.ctx;
    var isDark = document.body && document.body.classList && document.body.classList.contains('theme-dark');
    var alpha = isDark ? 0.12 : 0.09;

    ctx.save();
    ctx.beginPath();
    ctx.rect(plot.left, plot.top, plot.width, plot.height);
    ctx.clip();
    ctx.translate(plot.left + plot.width / 2, plot.top + plot.height / 2);
    ctx.rotate(-Math.PI / 7);
    ctx.fillStyle = isDark ? 'rgba(180,205,228,' + alpha + ')' : 'rgba(42,84,122,' + alpha + ')';
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    var px = Math.max(22, Math.min(56, Math.floor(plot.width * 0.11)));
    ctx.font = '700 ' + px + 'px Verdana, Arial, sans-serif';
    ctx.fillText(text, 0, 0);
    ctx.restore();
  };

  CanvasLineChart.prototype.drawAxes = function (plot, yr) {
    var ctx = this.ctx;
    var resolved = this.cfg.mode;
    var maxXTicks = chooseMaxXTicks(labels.length, resolved);
    var yTicks = chooseYTicks(resolved);
    var tickColor = 'rgba(60, 84, 112, 0.95)';
    var gridColor = 'rgba(120,140,165,0.26)';

    ctx.strokeStyle = gridColor;
    ctx.lineWidth = 1;
    for (var i = 0; i <= yTicks; i++) {
      var p = i / yTicks;
      var y = plot.top + p * plot.height;
      ctx.beginPath();
      ctx.moveTo(plot.left, y);
      ctx.lineTo(plot.right, y);
      ctx.stroke();

      var value = yr.max - p * (yr.max - yr.min);
      ctx.fillStyle = tickColor;
      ctx.font = '12px Verdana, Arial, sans-serif';
      ctx.textAlign = 'right';
      ctx.textBaseline = 'middle';
      ctx.fillText(formatValue(value, this.cfg.decimals), plot.left - 8, y);
    }

    var step = Math.max(1, Math.ceil(labels.length / maxXTicks));
    ctx.textAlign = 'center';
    ctx.textBaseline = 'top';
    for (var j = 0; j < labels.length; j += step) {
      var x = this.xForIndex(j, plot);
      ctx.beginPath();
      ctx.moveTo(x, plot.top);
      ctx.lineTo(x, plot.bottom);
      ctx.stroke();
      ctx.fillStyle = tickColor;
      ctx.fillText(compactDateLabel(labels[j]), x, plot.bottom + 7);
    }

    ctx.strokeStyle = 'rgba(60, 84, 112, 0.55)';
    ctx.beginPath();
    ctx.moveTo(plot.left, plot.top);
    ctx.lineTo(plot.left, plot.bottom);
    ctx.lineTo(plot.right, plot.bottom);
    ctx.stroke();

    ctx.fillStyle = tickColor;
    ctx.font = '12px Verdana, Arial, sans-serif';
    ctx.textAlign = 'center';
    ctx.fillText(ui.time_axis || 'Date/Heure', plot.left + plot.width / 2, this.h - 14);

    ctx.save();
    ctx.translate(14, plot.top + plot.height / 2);
    ctx.rotate(-Math.PI / 2);
    ctx.textAlign = 'center';
    ctx.fillText(this.cfg.yTitle, 0, 0);
    ctx.restore();
  };

  CanvasLineChart.prototype.drawLine = function (plot, yr) {
    var ctx = this.ctx;
    ctx.strokeStyle = this.cfg.color;
    ctx.lineWidth = 2.4;
    ctx.beginPath();

    var started = false;
    for (var i = 0; i < this.cfg.values.length; i++) {
      var v = this.cfg.values[i];
      if (!Number.isFinite(v)) {
        started = false;
        continue;
      }
      var x = this.xForIndex(i, plot);
      var y = this.yForValue(v, yr, plot);
      if (!started) {
        ctx.moveTo(x, y);
        started = true;
      } else {
        ctx.lineTo(x, y);
      }
    }
    ctx.stroke();
  };

  CanvasLineChart.prototype.nearestIndex = function (px, py, plot) {
    if (px < plot.left || px > plot.right || py < plot.top || py > plot.bottom) return null;
    var ratio = (px - plot.left) / Math.max(1, plot.width);
    var idx = Math.round(ratio * (labels.length - 1));
    idx = Math.max(0, Math.min(labels.length - 1, idx));

    if (Number.isFinite(this.cfg.values[idx])) return idx;

    var left = idx - 1;
    var right = idx + 1;
    while (left >= 0 || right < this.cfg.values.length) {
      if (right < this.cfg.values.length && Number.isFinite(this.cfg.values[right])) return right;
      if (left >= 0 && Number.isFinite(this.cfg.values[left])) return left;
      left -= 1;
      right += 1;
    }
    return null;
  };

  CanvasLineChart.prototype.drawHover = function (plot, yr) {
    if (this.hoverIndex === null) {
      this.tooltip.style.display = 'none';
      return;
    }
    var idx = this.hoverIndex;
    var value = this.cfg.values[idx];
    if (!Number.isFinite(value)) {
      this.tooltip.style.display = 'none';
      return;
    }

    var x = this.xForIndex(idx, plot);
    var y = this.yForValue(value, yr, plot);
    var ctx = this.ctx;

    ctx.strokeStyle = 'rgba(35, 92, 146, 0.5)';
    ctx.lineWidth = 1;
    ctx.setLineDash([]);
    ctx.beginPath();
    ctx.moveTo(x, plot.top);
    ctx.lineTo(x, plot.bottom);
    ctx.stroke();
    ctx.strokeStyle = 'rgba(210, 86, 43, 0.9)';
    ctx.lineWidth = 1.4;
    ctx.setLineDash([5, 4]);
    ctx.beginPath();
    ctx.moveTo(plot.left, y);
    ctx.lineTo(plot.right, y);
    ctx.stroke();
    ctx.setLineDash([]);

    ctx.fillStyle = this.cfg.color;
    ctx.beginPath();
    ctx.arc(x, y, 4, 0, Math.PI * 2);
    ctx.fill();

    this.tooltip.style.display = 'block';
    this.tooltip.innerHTML = '<strong>' + rawDateLabel(labels[idx]) + '</strong><br>' +
      this.cfg.yTitle + ': ' + formatValue(value, this.cfg.decimals);

    var left = x + 12;
    var top = y - 12;
    var maxLeft = this.w - this.tooltip.offsetWidth - 8;
    if (left > maxLeft) left = x - this.tooltip.offsetWidth - 12;
    if (left < 8) left = 8;
    if (top < 8) top = 8;
    this.tooltip.style.left = left + 'px';
    this.tooltip.style.top = top + 'px';
  };

  CanvasLineChart.prototype.draw = function () {
    var ctx = this.ctx;
    ctx.clearRect(0, 0, this.w, this.h);

    var plot = this.getPlot();
    var yr = this.yRange();
    this.drawWatermark(plot);
    this.drawAxes(plot, yr);
    this.drawLine(plot, yr);
    this.drawHover(plot, yr);
  };

  CanvasLineChart.prototype.onMove = function (e) {
    var rect = this.canvas.getBoundingClientRect();
    var px = e.clientX - rect.left;
    var py = e.clientY - rect.top;
    this.hoverIndex = this.nearestIndex(px, py, this.getPlot());
    this.draw();
  };

  CanvasLineChart.prototype.onTouch = function (e) {
    if (!e.touches || !e.touches[0]) return;
    var t = e.touches[0];
    var rect = this.canvas.getBoundingClientRect();
    var px = t.clientX - rect.left;
    var py = t.clientY - rect.top;
    this.hoverIndex = this.nearestIndex(px, py, this.getPlot());
    this.draw();
  };

  CanvasLineChart.prototype.onLeave = function () {
    this.hoverIndex = null;
    this.draw();
  };

  function destroyCharts() {
    for (var i = 0; i < charts.length; i++) {
      if (charts[i] && charts[i].tooltip && charts[i].tooltip.parentNode) {
        charts[i].tooltip.parentNode.removeChild(charts[i].tooltip);
      }
    }
    charts = [];
  }

  function updateToggleLabel(selectedMode) {
    if (!dToggle) return;
    var prefix = ui.density_label || 'Density';
    dToggle.textContent = prefix + ': ' + modeLabel(selectedMode);
  }

  function buildChart(id, yTitle, color, series, resolvedMode, opts) {
    var cv = document.getElementById(id);
    if (!cv) return;
    var values = (Array.isArray(series) ? series : []).map(safeNumber);
    var options = opts || {};
    var chart = new CanvasLineChart(cv, {
      mode: resolvedMode,
      yTitle: yTitle,
      color: color,
      values: values,
      decimals: Number.isFinite(options.decimals) ? options.decimals : valueDecimals(values),
      yMin: options.yMin,
      yMax: options.yMax
    });
    charts.push(chart);
  }

  function renderAll(selectedMode) {
    var resolved = resolveMode(selectedMode);
    destroyCharts();

    buildChart('chartT', l.T || 'Temperature', '#d04f2b', d.T, resolved);
    buildChart('chartH', l.H || 'Humidity', '#0f9d58', d.H, resolved);
    buildChart('chartP', l.P || 'Pressure', '#0f6cbf', d.P, resolved);
    buildChart('chartR', l.R || 'Rain', '#1269a8', rainSeries(), resolved);
    buildChart('chartW', l.W || 'Wind', '#7e3fa1', windSeries(), resolved);
    buildChart('chartB', l.B || 'Wind dir (°)', '#158f8b', d.B, resolved, { yMin: 0, yMax: 360, decimals: 0 });
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
    window.clearTimeout(resizeTimer);
    resizeTimer = window.setTimeout(function () {
      renderAll(selectedMode);
    }, 180);
  });
})();
