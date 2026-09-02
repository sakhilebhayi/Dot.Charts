import { API_BASE } from './api-base.js';
import { showFailure } from './ecosystem.js';

const checkButton = document.getElementById('checkButton');
const errorEl = document.getElementById('error');
const resultsEl = document.getElementById('results');
const symbolInput = document.getElementById('symbol');
const assetClassSelect = document.getElementById('assetClass');

checkButton.addEventListener('click', async () => {
  let failStatus = null;
  errorEl.style.display = 'none';
  resultsEl.style.display = 'none';
  checkButton.disabled = true;
  checkButton.textContent = 'Checking…';

  const symbol = symbolInput.value.trim();
  const assetClass = assetClassSelect.value;

  try {
    const response = await fetch(
      `${API_BASE}/options/vol-signal/${encodeURIComponent(symbol)}?asset_class=${encodeURIComponent(assetClass)}`,
      { headers: { Accept: 'application/json' } },
    );
    failStatus = response.status;
    const body = await response.json();

    if (!response.ok || body.success === false) {
      throw new Error(body.error || 'Could not read the options vol signal');
    }

    renderVolSignal(body.result);
  } catch (err) {
    showFailure(errorEl, err.message, failStatus);
  } finally {
    checkButton.disabled = false;
    checkButton.textContent = 'Check vol signal';
  }
});

function renderVolSignal(result) {
  document.getElementById('resultTitle').textContent = `${result.symbol} — options vol`;

  document.getElementById('mSpot').textContent = result.spot.toFixed(2);
  document.getElementById('mRealizedVol').textContent = `${result.realized_vol.current_annualized_pct.toFixed(1)}%`;
  document.getElementById('mVolRank').textContent = `${result.realized_vol.rank_pct.toFixed(0)}th pctile`;

  const volRegimeEl = document.getElementById('mVolRegime');
  volRegimeEl.textContent = result.vol_regime;
  volRegimeEl.className = `value regime-${result.vol_regime}`;

  document.getElementById('mSkew').textContent = result.skew.skew.toFixed(4);

  const skewRegimeEl = document.getElementById('mSkewRegime');
  skewRegimeEl.textContent = result.skew_regime;
  skewRegimeEl.className = `value regime-${result.skew_regime}`;

  const d = result.disclosure;
  document.getElementById('dAttribution').textContent = d.attribution;
  document.getElementById('dRisk').textContent = d.risk_disclosure;

  document.getElementById('results').style.display = 'block';
}
