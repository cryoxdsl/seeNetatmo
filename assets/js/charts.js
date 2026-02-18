(function () {
  const payload = window.chartPayload;
  const canvas = document.getElementById('weatherChart');
  if (!payload || !canvas) return;

  const ctx = canvas.getContext('2d');
  const dpr = window.devicePixelRatio || 1;
  const rect = canvas.getBoundingClientRect();
  canvas.width = Math.max(800, rect.width) * dpr;
  canvas.height = 360 * dpr;
  ctx.scale(dpr, dpr);

  const width = canvas.width / dpr;
  const height = canvas.height / dpr;
  const pad = 40;

  const t = payload.T.filter(v => v !== null);
  if (!t.length) {
    ctx.fillText('No chart data for selected period', 20, 30);
    return;
  }

  const min = Math.min(...t) - 2;
  const max = Math.max(...t) + 2;
  const len = payload.T.length;

  ctx.clearRect(0, 0, width, height);
  ctx.strokeStyle = '#d5e2ef';
  for (let i = 0; i <= 5; i++) {
    const y = pad + (i * (height - pad * 2) / 5);
    ctx.beginPath();
    ctx.moveTo(pad, y);
    ctx.lineTo(width - pad, y);
    ctx.stroke();
  }

  ctx.strokeStyle = '#0f5e9c';
  ctx.lineWidth = 2;
  ctx.beginPath();
  payload.T.forEach((v, i) => {
    if (v === null) return;
    const x = pad + (i * (width - pad * 2) / Math.max(1, len - 1));
    const y = height - pad - ((v - min) * (height - pad * 2) / (max - min));
    if (i === 0) ctx.moveTo(x, y);
    else ctx.lineTo(x, y);
  });
  ctx.stroke();

  ctx.fillStyle = '#1a2733';
  ctx.font = '12px sans-serif';
  ctx.fillText('Temperature (C)', pad, 20);
})();
