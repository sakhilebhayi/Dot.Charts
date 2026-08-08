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

function renderEquityCurve(points) {
  const svg = document.getElementById('equityCurve');
  svg.innerHTML = '';
  if (!points || points.length < 2) return;

  const width = svg.clientWidth || 860;
  const height = 160;
  const values = points.map((p) => p.equity);
  const min = Math.min(...values);
  const max = Math.max(...values);
  const range = max - min || 1;

  const coords = points.map((p, i) => {
    const x = (i / (points.length - 1)) * width;
    const y = height - ((p.equity - min) / range) * height;
    return `${x},${y}`;
  });

  const polyline = document.createElementNS('http://www.w3.org/2000/svg', 'polyline');
  polyline.setAttribute('points', coords.join(' '));
  polyline.setAttribute('fill', 'none');
  polyline.setAttribute('stroke', '#22d3ee');
  polyline.setAttribute('stroke-width', '2');
  svg.appendChild(polyline);
}
