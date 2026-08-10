# Forex Support Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add `forex` as a fourth asset class, backtestable with every existing strategy, reusing the existing equity/commodity `yfinance` fetch path unchanged, with a curated preset-symbol dropdown in the UI.

**Architecture:** `analytics/data/fetch.py`'s existing equity/commodity branch extends to include `forex` — no new fetch function, since `yfinance` forex tickers (`EURUSD=X` style) go through the identical code path. Every other touchpoint (Pydantic schema, Laravel validation, frontend dropdowns/JS) gets the same `"forex"` string added wherever `"commodity"` already appears.

**Tech Stack:** Python (`yfinance`, already a dependency), PHP/Laravel, vanilla JS/HTML.

## Global Constraints

- No new fetch function or engine changes — forex reuses `_fetch_equity()` exactly, per the spec's Architecture section.
- Symbol input is a curated preset dropdown (EUR/USD, GBP/USD, USD/JPY, USD/ZAR → `EURUSD=X`, `GBPUSD=X`, `USDJPY=X`, `USDZAR=X`), not free text, per the spec's Symbol Input section.
- `frontend/history.html` needs no change — confirmed by inspection to have no asset-class filter (per the spec's Integration Points section).

---

### Task 1: `fetch_ohlcv` accepts `forex` + schema update

**Files:**
- Modify: `analytics/data/fetch.py`
- Modify: `analytics/schemas.py`
- Modify: `analytics/tests/test_fetch.py`

**Interfaces:**
- Consumes: nothing new.
- Produces: `fetch_ohlcv(symbol, "forex", start_date, end_date, interval)` now returns a normalized OHLCV DataFrame instead of raising — later tasks (Laravel, frontend) rely on `"forex"` being a valid, end-to-end-working asset class string.

- [ ] **Step 1: Write the failing test, and fix the now-incorrect existing test**

Append to `analytics/tests/test_fetch.py`:

```python
def test_fetch_ohlcv_forex_reuses_the_yfinance_path(mocker):
    mocker.patch("data.fetch.yf.download", side_effect=_fake_yf_download)

    df = fetch_ohlcv("EURUSD=X", "forex", "2023-01-01", "2023-01-05")

    assert list(df.columns) == ["open", "high", "low", "close", "volume"]
    assert len(df) == 5
    assert df.index.tz is not None
```

The existing `test_fetch_ohlcv_unsupported_asset_class_raises` test uses
`"forex"` as its example of an *unsupported* asset class — that example
becomes factually wrong once this task lands (forex will be genuinely
supported, so calling `fetch_ohlcv` with it will no longer raise). Fix the
test's intent (an actually-unsupported class must still raise) rather
than deleting the coverage — change its body to:

```python
def test_fetch_ohlcv_unsupported_asset_class_raises():
    with pytest.raises(DataFetchError):
        fetch_ohlcv("AAPL", "not_a_real_asset_class", "2023-01-01", "2023-01-05")
```

- [ ] **Step 2: Run tests to verify the new one fails and the fixed one still passes**

Run: `cd analytics && .venv/bin/pytest tests/test_fetch.py -v`
Expected: `test_fetch_ohlcv_forex_reuses_the_yfinance_path` FAILS with `DataFetchError: Unsupported asset_class: forex`; `test_fetch_ohlcv_unsupported_asset_class_raises` PASSES (its new "not_a_real_asset_class" input is still genuinely unsupported).

- [ ] **Step 3: Write the implementation**

In `analytics/data/fetch.py`, change:

```python
    if asset_class in ("equity", "commodity"):
        # Both are "fetch this Yahoo Finance ticker verbatim" — commodities
        # and indices (GC=F, SI=F, CL=F, ^GDAXI) work through the exact same
        # yfinance path as stocks; there is no behavioral difference beyond
        # the label, so no separate fetch function exists for commodities.
        df = _fetch_equity(symbol, start_date, end_date, interval)
```

to:

```python
    if asset_class in ("equity", "commodity", "forex"):
        # All three are "fetch this Yahoo Finance ticker verbatim" —
        # commodities/indices (GC=F, SI=F, CL=F, ^GDAXI) and forex pairs
        # (EURUSD=X, USDJPY=X) work through the exact same yfinance path as
        # stocks; there is no behavioral difference beyond the label, so no
        # separate fetch function exists for either.
        df = _fetch_equity(symbol, start_date, end_date, interval)
```

In `analytics/schemas.py`, change:

```python
AssetClass = Literal["equity", "crypto", "commodity"]
```

to:

```python
AssetClass = Literal["equity", "crypto", "commodity", "forex"]
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd analytics && .venv/bin/pytest tests/test_fetch.py -v`
Expected: PASS (all tests in the file)

- [ ] **Step 5: Commit**

```bash
git add analytics/data/fetch.py analytics/schemas.py analytics/tests/test_fetch.py
git commit -m "feat(forex): accept forex asset class in fetch_ohlcv and AssetClass schema"
```

---

### Task 2: `/backtest` endpoint smoke test

**Files:**
- Modify: `analytics/tests/test_backtest_endpoint.py`

**Interfaces:**
- Consumes: `fetch_ohlcv_cached`'s existing mock pattern (`mocker.patch("main.fetch_ohlcv_cached", ...)`, already used throughout this file); `"forex"` as a valid `asset_class` from Task 1.
- Produces: nothing new for later tasks — this is a coverage-only task.

- [ ] **Step 1: Write the failing test**

Append to `analytics/tests/test_backtest_endpoint.py`, near `test_backtest_commodity_returns_metrics_and_trades`:

```python
def test_backtest_forex_returns_metrics_and_trades(mocker):
    mocker.patch("main.fetch_ohlcv_cached", return_value=_synthetic_uptrend_df())

    response = client.post(
        "/backtest",
        json={
            "symbol": "EURUSD=X",
            "asset_class": "forex",
            "strategy": "ma_crossover",
            "start_date": "2023-01-01",
            "end_date": "2023-04-10",
        },
    )

    assert response.status_code == 200
    body = response.json()
    assert body["asset_class"] == "forex"
    assert body["symbol"] == "EURUSD=X"
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd analytics && .venv/bin/pytest tests/test_backtest_endpoint.py -v -k forex`
Expected: FAIL — this should actually already PASS if Task 1 is complete and committed, since `main.py`'s `/backtest` endpoint is asset-class-agnostic (it just forwards to `fetch_ohlcv_cached`, already patched by the mock in this test). Run this step to confirm: if it unexpectedly fails, that reveals a real gap Task 1 missed (e.g. `schemas.py`'s `AssetClass` Literal not actually being used by `BacktestRequest`, or some other validation layer) — stop and investigate before proceeding, per the plan's "stop when blocked" rule.

- [ ] **Step 3: Run the full analytics suite**

Run: `cd analytics && .venv/bin/pytest -v`
Expected: All tests pass, no regressions.

- [ ] **Step 4: Commit**

```bash
git add analytics/tests/test_backtest_endpoint.py
git commit -m "test(forex): add /backtest endpoint smoke test for forex asset class"
```

---

### Task 3: Laravel validation

**Files:**
- Modify: `backend/app/Http/Controllers/BacktestController.php`
- Test: `backend/tests/Feature/BacktestControllerTest.php`

**Interfaces:**
- Consumes: `"forex"` as a valid asset-class string (must match Task 1's schema exactly).
- Produces: nothing new for later tasks.

- [ ] **Step 1: Write the failing test**

Append to `backend/tests/Feature/BacktestControllerTest.php`, near `test_store_accepts_commodity_asset_class`:

```php
    public function test_store_accepts_forex_asset_class(): void
    {
        Http::fake([
            '*/backtest' => Http::response([
                'symbol' => 'EURUSD=X',
                'asset_class' => 'forex',
                'strategy' => 'ma_crossover',
                'params' => ['fast_window' => 20, 'slow_window' => 50],
                'start_date' => '2023-01-01',
                'end_date' => '2026-01-01',
                'metrics' => [
                    'total_return_pct' => 2.0,
                    'win_rate_pct' => 45.0,
                    'max_drawdown_pct' => -1.5,
                    'sharpe_ratio' => 0.7,
                    'trade_count' => 10,
                    'losing_trade_count' => 5,
                ],
                'equity_curve' => [['time' => '2023-01-01T00:00:00', 'equity' => 10000.0]],
                'trades' => [],
            ], 200),
        ]);

        $response = $this->postJson('/api/backtests', [
            'symbol' => 'EURUSD=X',
            'asset_class' => 'forex',
            'strategy' => 'ma_crossover',
            'start_date' => '2023-01-01',
            'end_date' => '2026-01-01',
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('result.asset_class', 'forex');

        $this->assertDatabaseHas('backtest_runs', [
            'symbol' => 'EURUSD=X',
            'asset_class' => 'forex',
            'status' => 'complete',
        ]);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test --filter=test_store_accepts_forex_asset_class`
Expected: FAIL — the `asset_class` field's `in:equity,crypto,commodity` validation rule rejects `forex` with a 422, so `$response->assertOk()` fails.

- [ ] **Step 3: Write the implementation**

In `backend/app/Http/Controllers/BacktestController.php`, change:

```php
            'asset_class' => 'required|in:equity,crypto,commodity',
```

to:

```php
            'asset_class' => 'required|in:equity,crypto,commodity,forex',
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && php artisan test --filter=test_store_accepts_forex_asset_class`
Expected: PASS

- [ ] **Step 5: Run the full Laravel suite**

Run: `cd backend && php artisan test`
Expected: All tests pass, no regressions.

- [ ] **Step 6: Commit**

```bash
git add backend/app/Http/Controllers/BacktestController.php backend/tests/Feature/BacktestControllerTest.php
git commit -m "feat(forex): accept forex asset class in Laravel validation"
```

---

### Task 4: Frontend preset dropdown + branching logic

**Files:**
- Modify: `frontend/backtest.html`
- Modify: `frontend/src/backtest.js`

**Interfaces:**
- Consumes: `"forex"` as a valid asset-class value (Tasks 1-3, complete).
- Produces: nothing new for later tasks — this is the final task in the plan.

- [ ] **Step 1: Add the forex option and preset dropdown to `backtest.html`**

In `frontend/backtest.html`, change:

```html
        <select id="symbolCommodity" style="display:none">
          <option value="GC=F">Gold</option>
          <option value="SI=F">Silver</option>
          <option value="CL=F">Oil (WTI)</option>
          <option value="^GDAXI">GER30 / GER40 (DAX)</option>
        </select>
```

to:

```html
        <select id="symbolCommodity" style="display:none">
          <option value="GC=F">Gold</option>
          <option value="SI=F">Silver</option>
          <option value="CL=F">Oil (WTI)</option>
          <option value="^GDAXI">GER30 / GER40 (DAX)</option>
        </select>
        <select id="symbolForex" style="display:none">
          <option value="EURUSD=X">EUR/USD</option>
          <option value="GBPUSD=X">GBP/USD</option>
          <option value="USDJPY=X">USD/JPY</option>
          <option value="USDZAR=X">USD/ZAR</option>
        </select>
```

and change:

```html
        <select id="assetClass">
          <option value="equity">Equity</option>
          <option value="crypto">Crypto</option>
          <option value="commodity">Commodity</option>
        </select>
```

to:

```html
        <select id="assetClass">
          <option value="equity">Equity</option>
          <option value="crypto">Crypto</option>
          <option value="commodity">Commodity</option>
          <option value="forex">Forex</option>
        </select>
```

- [ ] **Step 2: Extend `backtest.js`'s branching logic**

In `frontend/src/backtest.js`, change:

```javascript
const symbolCommoditySelect = document.getElementById('symbolCommodity');
```

to:

```javascript
const symbolCommoditySelect = document.getElementById('symbolCommodity');
const symbolForexSelect = document.getElementById('symbolForex');
```

Change:

```javascript
assetClassSelect.addEventListener('change', () => {
  const isCommodity = assetClassSelect.value === 'commodity';
  symbolInput.style.display = isCommodity ? 'none' : '';
  symbolCommoditySelect.style.display = isCommodity ? '' : 'none';
});
```

to:

```javascript
assetClassSelect.addEventListener('change', () => {
  const isCommodity = assetClassSelect.value === 'commodity';
  const isForex = assetClassSelect.value === 'forex';
  symbolInput.style.display = (isCommodity || isForex) ? 'none' : '';
  symbolCommoditySelect.style.display = isCommodity ? '' : 'none';
  symbolForexSelect.style.display = isForex ? '' : 'none';
});
```

Change:

```javascript
function currentSymbol() {
  return assetClassSelect.value === 'commodity'
    ? symbolCommoditySelect.value
    : symbolInput.value.trim();
}
```

to:

```javascript
function currentSymbol() {
  if (assetClassSelect.value === 'commodity') return symbolCommoditySelect.value;
  if (assetClassSelect.value === 'forex') return symbolForexSelect.value;
  return symbolInput.value.trim();
}
```

Change the re-run prefill block:

```javascript
    if (prefill.asset_class === 'commodity') {
      symbolCommoditySelect.value = prefill.symbol;
    } else {
      symbolInput.value = prefill.symbol;
    }
```

to:

```javascript
    if (prefill.asset_class === 'commodity') {
      symbolCommoditySelect.value = prefill.symbol;
    } else if (prefill.asset_class === 'forex') {
      symbolForexSelect.value = prefill.symbol;
    } else {
      symbolInput.value = prefill.symbol;
    }
```

- [ ] **Step 3: Manual verification**

1. Start the three dev servers: analytics (`cd analytics && .venv/bin/uvicorn main:app --port 8001`), backend (`chartsense-backend` launch config, port 8000), frontend (`chartsense` launch config, port 3000).
2. Open `http://localhost:3000/backtest.html`, select "Forex" from the Asset class dropdown, confirm the symbol text box hides and a "EUR/USD"/"GBP/USD"/"USD/JPY"/"USD/ZAR" dropdown appears in its place.
3. Select "EUR/USD", pick MA Crossover as the strategy, a real date range (e.g. `2023-01-01` to `2023-06-01`), click "Run backtest", and confirm it renders a result with no console errors.
4. Open `http://localhost:3000/history.html`, find the just-created forex run, click "Re-run", and confirm the backtest page comes back with Asset class set to "Forex" and the forex preset dropdown showing "EUR/USD" selected (not the free-text symbol box).
5. Stop the dev servers.

- [ ] **Step 4: Commit**

```bash
git add frontend/backtest.html frontend/src/backtest.js
git commit -m "feat(forex): add forex preset dropdown and asset-class branching to frontend"
```
