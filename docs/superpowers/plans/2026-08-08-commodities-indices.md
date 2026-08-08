# Commodities & Indices Asset Class Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add `asset_class: "commodity"` end-to-end (Python, Laravel, frontend), covering Gold (`GC=F`), Silver (`SI=F`), Oil/WTI (`CL=F`), and GER30/GER40 (`^GDAXI`), supported by all three existing strategy presets.

**Architecture:** No new components. `commodity` routes through the exact same yfinance-backed fetch path equities already use — `_fetch_equity` in `analytics/data/fetch.py` is not actually equity-specific, it fetches any Yahoo Finance ticker. The frontend's new preset dropdown carries the real ticker as its `<option value>` directly, so no symbol-mapping layer exists anywhere in the stack.

**Tech Stack:** Same as the parent slice — Python/FastAPI/yfinance, Laravel 12/PHP 8.2, vanilla JS/Vite frontend.

## Global Constraints

- Exactly four instruments in this slice: Gold (`GC=F`), Silver (`SI=F`), Oil/WTI (`CL=F`), GER30/GER40 (`^GDAXI`, one ticker for both retail names).
- All three strategy presets (`ma_crossover`, `rsi_mean_reversion`, `method_714`) support `commodity` — no strategy-level gating.
- No new Python module, no symbol-mapping table on the backend — the ticker the frontend sends *is* the yfinance ticker.
- Free-text symbol entry (equity/crypto) is untouched; commodities are preset-dropdown-only in this slice.
- No live-network calls in automated tests — mock `yf.download` in Python, mock the outbound HTTP call in Laravel.

---

## File Structure

```
ChartSense/
├── analytics/
│   ├── schemas.py                          # MODIFY — AssetClass gains "commodity"
│   ├── data/fetch.py                       # MODIFY — commodity branch
│   └── tests/
│       ├── test_fetch.py                   # MODIFY — commodity case
│       └── test_backtest_endpoint.py       # MODIFY — commodity request case
├── backend/
│   ├── app/Http/Controllers/BacktestController.php  # MODIFY — validation
│   └── tests/Feature/BacktestControllerTest.php      # MODIFY — commodity case
└── frontend/
    ├── backtest.html                       # MODIFY — asset class + commodity symbol dropdown
    └── src/backtest.js                     # MODIFY — toggle logic
```

---

### Task 1: Python — `commodity` asset class support

**Files:**
- Modify: `analytics/schemas.py`
- Modify: `analytics/data/fetch.py`
- Test: `analytics/tests/test_fetch.py`
- Test: `analytics/tests/test_backtest_endpoint.py`

**Interfaces:**
- Consumes: `data.fetch._fetch_equity` (existing, unchanged signature) — reused for the commodity branch.
- Produces: `schemas.AssetClass` now includes `"commodity"`; `fetch_ohlcv(symbol, "commodity", start_date, end_date, interval)` returns the same normalized DataFrame shape as `"equity"`. Used by Task 2 (Laravel just passes the string through) and Task 3 (frontend sends it).

- [ ] **Step 1: Write the failing tests**

```python
# analytics/tests/test_fetch.py — add this test (reuses _fake_yf_download from the equity test above it)
def test_fetch_ohlcv_commodity_reuses_the_yfinance_path(mocker):
    mocker.patch("data.fetch.yf.download", side_effect=_fake_yf_download)

    df = fetch_ohlcv("GC=F", "commodity", "2023-01-01", "2023-01-05")

    assert list(df.columns) == ["open", "high", "low", "close", "volume"]
    assert len(df) == 5
    assert df.index.tz is not None
```

```python
# analytics/tests/test_backtest_endpoint.py — add this test
def test_backtest_commodity_returns_metrics_and_trades(mocker):
    mocker.patch("main.fetch_ohlcv", return_value=_synthetic_uptrend_df())

    response = client.post(
        "/backtest",
        json={
            "symbol": "GC=F",
            "asset_class": "commodity",
            "strategy": "ma_crossover",
            "start_date": "2023-01-01",
            "end_date": "2023-04-10",
        },
    )

    assert response.status_code == 200
    body = response.json()
    assert body["asset_class"] == "commodity"
    assert body["symbol"] == "GC=F"
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd analytics && .venv/bin/pytest tests/test_fetch.py tests/test_backtest_endpoint.py -v`
Expected: FAIL — `test_fetch_ohlcv_commodity_reuses_the_yfinance_path` raises `DataFetchError` (`"Unsupported asset_class: commodity"`); `test_backtest_commodity_returns_metrics_and_trades` gets a `422` from Pydantic (`asset_class` not in the `Literal`)

- [ ] **Step 3: Write minimal implementation**

```python
# analytics/schemas.py — change line 4 only
AssetClass = Literal["equity", "crypto", "commodity"]
```

```python
# analytics/data/fetch.py — change the dispatch in fetch_ohlcv()
def fetch_ohlcv(
    symbol: str,
    asset_class: str,
    start_date: str,
    end_date: str,
    interval: str = "1d",
) -> pd.DataFrame:
    if asset_class in ("equity", "commodity"):
        # Both are "fetch this Yahoo Finance ticker verbatim" — commodities
        # and indices (GC=F, SI=F, CL=F, ^GDAXI) work through the exact same
        # yfinance path as stocks; there is no behavioral difference beyond
        # the label, so no separate fetch function exists for commodities.
        df = _fetch_equity(symbol, start_date, end_date, interval)
    elif asset_class == "crypto":
        df = _fetch_crypto(symbol, start_date, end_date, interval)
    else:
        raise DataFetchError(f"Unsupported asset_class: {asset_class}")

    # Both yfinance and ccxt return a tz-naive DatetimeIndex. Strategies that
    # do timezone-aware session math (e.g. method_714) require a tz-aware
    # index — tz_convert() raises on a naive one — so every OHLCV frame
    # leaving this module is localized to UTC here, once, regardless of
    # source, rather than leaving each strategy to discover/handle this.
    if df.index.tz is None:
        df.index = df.index.tz_localize("UTC")
    return df
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `.venv/bin/pytest tests/test_fetch.py tests/test_backtest_endpoint.py -v`
Expected: PASS (both new tests)

- [ ] **Step 5: Run the full Python test suite**

Run: `.venv/bin/pytest -v`
Expected: all 21 tests PASS (19 existing + 2 new)

- [ ] **Step 6: Commit**

```bash
cd /Users/sakhilebhayi/Dot/ChartSense
git add analytics/schemas.py analytics/data/fetch.py analytics/tests/test_fetch.py analytics/tests/test_backtest_endpoint.py
git commit -m "feat(analytics): add commodity asset class (reuses the yfinance fetch path)"
```

---

### Task 2: Laravel — accept `commodity` in validation

**Files:**
- Modify: `backend/app/Http/Controllers/BacktestController.php`
- Test: `backend/tests/Feature/BacktestControllerTest.php`

**Interfaces:**
- Consumes: nothing new — `AnalyticsServiceClient::runBacktest` and `DisclosureFormatter::format` are already generic over `asset_class` and need no changes.
- Produces: `POST /api/backtests` accepts `asset_class: "commodity"`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// backend/tests/Feature/BacktestControllerTest.php — add this test to the existing class

public function test_store_accepts_commodity_asset_class(): void
{
    Http::fake([
        '*/backtest' => Http::response([
            'symbol' => 'GC=F',
            'asset_class' => 'commodity',
            'strategy' => 'ma_crossover',
            'params' => ['fast_window' => 20, 'slow_window' => 50],
            'start_date' => '2023-01-01',
            'end_date' => '2026-01-01',
            'metrics' => [
                'total_return_pct' => 5.0,
                'win_rate_pct' => 50.0,
                'max_drawdown_pct' => -3.0,
                'sharpe_ratio' => 0.9,
                'trade_count' => 12,
                'losing_trade_count' => 6,
            ],
            'equity_curve' => [['time' => '2023-01-01T00:00:00', 'equity' => 10000.0]],
            'trades' => [],
        ], 200),
    ]);

    $response = $this->postJson('/api/backtests', [
        'symbol' => 'GC=F',
        'asset_class' => 'commodity',
        'strategy' => 'ma_crossover',
        'start_date' => '2023-01-01',
        'end_date' => '2026-01-01',
    ]);

    $response->assertOk();
    $response->assertJsonPath('success', true);
    $response->assertJsonPath('result.asset_class', 'commodity');

    $this->assertDatabaseHas('backtest_runs', [
        'symbol' => 'GC=F',
        'asset_class' => 'commodity',
        'status' => 'complete',
    ]);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test --filter=test_store_accepts_commodity_asset_class`
Expected: FAIL — `422` (validation rejects `asset_class: commodity`, not in `equity,crypto`)

- [ ] **Step 3: Write minimal implementation**

```php
// backend/app/Http/Controllers/BacktestController.php — change one line in store()
            'asset_class' => 'required|in:equity,crypto,commodity',
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=test_store_accepts_commodity_asset_class`
Expected: PASS

- [ ] **Step 5: Run the full Laravel test suite**

Run: `php artisan test`
Expected: all 28 tests PASS (27 existing + 1 new)

- [ ] **Step 6: Commit**

```bash
git add backend/app/Http/Controllers/BacktestController.php backend/tests/Feature/BacktestControllerTest.php
git commit -m "feat(backend): accept commodity asset class in POST /api/backtests"
```

---

### Task 3: Frontend — commodity preset dropdown + manual verification

**Files:**
- Modify: `frontend/backtest.html`
- Modify: `frontend/src/backtest.js`

**Interfaces:**
- Consumes: `POST /api/backtests` (Task 2) — no contract change, just a new `asset_class` value and a ticker-as-symbol the backend already accepts.
- Produces: end-user-visible UI. No other task depends on this one.

**Note on testing:** matches the existing project convention (no JS test framework) — verification is manual, in the browser, against real data.

- [ ] **Step 1: Add the commodity option and a new preset dropdown**

```html
<!-- frontend/backtest.html — change the asset class <select> (around line 56-59) -->
        <select id="assetClass">
          <option value="equity">Equity</option>
          <option value="crypto">Crypto</option>
          <option value="commodity">Commodity</option>
        </select>
```

```html
<!-- frontend/backtest.html — replace the Symbol <div> (around lines 50-53) with both fields, symbol-commodity hidden by default -->
      <div>
        <label for="symbol">Symbol</label>
        <input id="symbol" placeholder="AAPL or BTC/USDT" value="AAPL" />
        <select id="symbolCommodity" style="display:none">
          <option value="GC=F">Gold</option>
          <option value="SI=F">Silver</option>
          <option value="CL=F">Oil (WTI)</option>
          <option value="^GDAXI">GER30 / GER40 (DAX)</option>
        </select>
      </div>
```

- [ ] **Step 2: Toggle between the two symbol inputs and use whichever is active**

```js
// frontend/src/backtest.js — add near the top, after the existing const declarations
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
```

```js
// frontend/src/backtest.js — change the payload construction inside the click handler
  const payload = {
    symbol: currentSymbol(),
    asset_class: document.getElementById('assetClass').value,
    strategy: document.getElementById('strategy').value,
    start_date: document.getElementById('startDate').value,
    end_date: document.getElementById('endDate').value,
    params: {},
  };
```

- [ ] **Step 3: Manually verify end to end**

Run the three dev servers (analytics on 8001, `php artisan serve` on 8000, `npm run dev` on 3000 — see the parent slice's plan for exact commands), open `http://localhost:3000/backtest.html`, and:

1. Select "Commodity" in the asset-class dropdown — confirm the free-text symbol field hides and the Gold/Silver/Oil/GER30-40 dropdown appears.
2. Run a backtest with Gold + MA Crossover, dates `2023-01-01` to `2026-01-01` — confirm it returns real (non-error) metrics.
3. Run one more with GER30/GER40 + 714 Method (crypto-style shorter date range, e.g. `2025-06-01` to `2025-08-01`, since 714 Method needs hourly data) — confirm it returns real metrics without error.
4. Switch back to "Equity" — confirm the free-text field reappears and still works (regression check on the existing AAPL flow).

- [ ] **Step 4: Commit**

```bash
git add frontend/backtest.html frontend/src/backtest.js
git commit -m "feat(frontend): add commodity preset dropdown (gold/silver/oil/GER30-40)"
```

---

## Plan Self-Review Notes

- **Spec coverage:** all four instruments (Task 3's dropdown), all three strategies (no strategy-level gating exists anywhere in the touched code, so this is true by construction), the `commodity` asset class end-to-end (Tasks 1-2), and the preset-dropdown UI decision (Task 3) are all covered.
- **Consistency check:** `AssetClass` (Task 1) is consumed identically by Laravel's validation string (Task 2, plain string, no shared enum — matches how `equity`/`crypto` already work) and the frontend's `<option value>` (Task 3). No new function signatures are introduced that a later task depends on beyond what's already documented above.
- **No placeholders:** every step has complete, real code.
