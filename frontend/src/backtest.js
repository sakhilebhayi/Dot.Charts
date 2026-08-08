const API_BASE = 'http://localhost:8000/api';

const runButton = document.getElementById('runButton');
const errorEl = document.getElementById('error');
const resultsEl = document.getElementById('results');
const assetClassSelect = document.getElementById('assetClass');
const symbolInput = document.getElementById('symbol');
const symbolCommoditySelect = document.getElementById('symbolCommodity');

assetClassSelect.addEventListener('change', () => {
  const isCommodity = assetClassSelect.value === 'commodity';
  symbolInput.style.display = isCommodity ? 'none' : '';
  symbolCommoditySelect.style.display = isCommodity ? '' : 'none';
});

function currentSymbol() {
  return assetClassSelect.value === 'commodity'
    ? symbolCommoditySelect.value
    : symbolInput.value.trim();
}

runButton.addEventListener('click', async () => {
  errorEl.style.display = 'none';
  resultsEl.style.display = 'none';
  runButton.disabled = true;
  runButton.textContent = 'Running…';

  const payload = {
    symbol: currentSymbol(),
    asset_class: document.getElementById('assetClass').value,
    strategy: document.getElementById('strategy').value,
    start_date: document.getElementById('startDate').value,
    end_date: document.getElementById('endDate').value,
    params: {},
  };

  try {
    const response = await fetch(`${API_BASE}/backtests`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify(payload),
    });
    const body = await response.json();

    if (!response.ok || body.success === false) {
      throw new Error(body.error || 'Backtest failed');
    }

    renderResult(body.result);
  } catch (err) {
    errorEl.textContent = err.message;
    errorEl.style.display = 'block';
  } finally {
    runButton.disabled = false;
    runButton.textContent = 'Run backtest';
  }
});

function renderResult(result) {
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

  resultsEl.style.display = 'block';
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
