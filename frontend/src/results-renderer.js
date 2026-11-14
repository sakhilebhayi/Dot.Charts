export function renderBacktestResult(result) {
  document.getElementById('resultTitle').textContent = `${result.symbol} — ${result.strategy}`;

  const m = result.metrics;
  document.getElementById('mTotalReturn').textContent = `${m.total_return_pct.toFixed(2)}%`;
  document.getElementById('mWinRate').textContent = `${m.win_rate_pct.toFixed(1)}%`;
  document.getElementById('mDrawdown').textContent = `${m.max_drawdown_pct.toFixed(2)}%`;
  document.getElementById('mSharpe').textContent = m.sharpe_ratio == null ? '—' : m.sharpe_ratio.toFixed(2);
  document.getElementById('mTrades').textContent = m.trade_count;
  document.getElementById('mLosingTrades').textContent = m.losing_trade_count;

  renderEquityCurve(result.equity_curve);

  const d = result.disclosure;
  document.getElementById('dConfidence').textContent = `Confidence: ${d.confidence_band}`;
  document.getElementById('dAttribution').textContent = d.attribution;
  document.getElementById('dRisk').textContent = d.risk_disclosure;

  document.getElementById('results').style.display = 'block';
}

// The curve is drawn in a fixed 1000 x 160 box that the SVG scales to whatever width the panel has (viewBox, preserveAspectRatio
// none, a non-scaling stroke), so it needs no redraw when the window or the phone is resized or rotated.
const BOX_W = 1000;
const BOX_H = 160;

function renderEquityCurve(points) {
  const svg = document.getElementById('equityCurve');
  svg.replaceChildren();
  if (!points || points.length < 2) return;

  svg.setAttribute('viewBox', `0 0 ${BOX_W} ${BOX_H}`);
  svg.setAttribute('preserveAspectRatio', 'none');
  svg.setAttribute('role', 'img');
  svg.setAttribute('aria-label', 'Equity curve of the run');

  const values = points.map((p) => p.equity);
  const min = Math.min(...values);
  const max = Math.max(...values);
  const range = max - min || 1;

  const coords = points.map((p, i) => {
    const x = (i / (points.length - 1)) * BOX_W;
    const y = BOX_H - ((p.equity - min) / range) * BOX_H;
    return `${x.toFixed(1)},${y.toFixed(1)}`;
  });

  const polyline = document.createElementNS('http://www.w3.org/2000/svg', 'polyline');
  polyline.setAttribute('points', coords.join(' '));
  polyline.setAttribute('class', 'eq-line');
  svg.appendChild(polyline);
}
