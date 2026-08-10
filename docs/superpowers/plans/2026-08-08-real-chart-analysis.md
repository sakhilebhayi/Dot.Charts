# Real Chart Analysis Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace `/api/chart/analyze`'s hardcoded placeholder analysis with real technical analysis computed from real market data for a symbol resolved via OCR or an explicit override, reusing the existing SMC swing-pivot engine.

**Architecture:** A new `analytics/analysis/chart_analysis.py` module computes trend/structure/supports/resistances/signal from real OHLCV (via `fetch_ohlcv_cached` + the existing `method_714/smc.py` functions), exposed as a new `POST /chart-analysis` endpoint on the analytics service. Laravel's `ChartAnalysisController` calls it through a new `AnalyticsServiceClient::analyzeChart` method when a symbol is resolved (override or OCR), falling back to today's exact placeholder response when no symbol is available or the analytics call fails.

**Tech Stack:** Python (`pandas`, `pandas_ta`, existing `fetch_ohlcv_cached` and `smc.py`), PHP/Laravel, vanilla JS/HTML.

## Global Constraints

- Reuses `strategies/method_714/smc.py`'s `compute_swing_pivots`/`compute_structure` unchanged — no new pivot/structure logic (per spec's Architecture section).
- Confidence here is a simple 2-component trend/structure agreement score, explicitly distinct from 714 Method's full weighted confidence system, and the response's `summary` text must say so (per spec's Architecture section).
- Fallback path (no symbol resolved, or analytics call fails) returns today's exact hardcoded placeholder response, byte-for-byte unchanged — all 4 existing `ChartAnalysisTest` tests must keep passing with no changes to their bodies (per spec's Testing section).
- `market` → `asset_class` mapping: `stocks`→`equity`, `crypto`→`crypto`, `forex`→`forex` (per spec's Request/Response Contract section).

---

### Task 1: `compute_chart_analysis` — real trend/structure/supports/resistances/signal

**Files:**
- Create: `analytics/analysis/__init__.py` (empty, makes `analysis` a package)
- Create: `analytics/analysis/chart_analysis.py`
- Test: `analytics/tests/test_chart_analysis.py`

**Interfaces:**
- Consumes: `fetch_ohlcv_cached(symbol, asset_class, start_date, end_date, interval)` from `analytics/data/cache.py`; `compute_swing_pivots(df, piv_len=5)` and `compute_structure(df_with_pivots)` from `analytics/strategies/method_714/smc.py` — both already produce a DataFrame with `swing_high`, `swing_low` columns (from `compute_swing_pivots`) and `structure_dir`, `bos`, `choch`, `bull_break`, `bear_break` columns (from `compute_structure`), all verified against the existing SMC test suite.
- Produces: `compute_chart_analysis(symbol: str, asset_class: str, interval: str = "1d") -> dict` with keys `signal`, `confidence`, `trend`, `patterns`, `supports`, `resistances`, `summary` — Task 2's endpoint relies on this exact function name, signature, and key set.

- [ ] **Step 1: Write the failing test**

```python
# analytics/tests/test_chart_analysis.py
import pandas as pd
from analysis.chart_analysis import compute_chart_analysis


def _bullish_structure_df() -> pd.DataFrame:
    # 60 bars flat around 100 (establishes swing pivots + EMA baseline),
    # then a clean uptrend for 60 bars that breaks the prior swing high --
    # deterministic bullish trend + bullish structure break, no mocks
    # needed beyond fetch_ohlcv_cached itself.
    idx = pd.date_range("2023-01-01", periods=120, freq="D", tz="UTC")
    flat = [100.0 + (i % 3) * 0.1 for i in range(60)]  # tiny noise, real pivots form
    uptrend = [100.0 + i * 1.5 for i in range(1, 61)]
    close = pd.Series(flat + uptrend, index=idx)
    high = close + 0.5
    low = close - 0.5
    return pd.DataFrame({"open": close, "high": high, "low": low, "close": close, "volume": 1000})


def test_compute_chart_analysis_detects_bullish_trend_and_structure(mocker):
    mocker.patch(
        "analysis.chart_analysis.fetch_ohlcv_cached",
        return_value=_bullish_structure_df(),
    )

    result = compute_chart_analysis("AAPL", "equity", interval="1d")

    assert result["trend"] == "Bullish"
    assert result["signal"] == "Buy"
    assert result["confidence"] > 50
    assert len(result["supports"]) > 0
    assert len(result["resistances"]) > 0
    assert "structure" in result["summary"].lower()
    assert "714" in result["summary"] or "not" in result["summary"].lower()


def test_compute_chart_analysis_detects_bearish_trend_and_structure(mocker):
    idx = pd.date_range("2023-01-01", periods=120, freq="D", tz="UTC")
    flat = [200.0 - (i % 3) * 0.1 for i in range(60)]
    downtrend = [200.0 - i * 1.5 for i in range(1, 61)]
    close = pd.Series(flat + downtrend, index=idx)
    high = close + 0.5
    low = close - 0.5
    df = pd.DataFrame({"open": close, "high": high, "low": low, "close": close, "volume": 1000})
    mocker.patch("analysis.chart_analysis.fetch_ohlcv_cached", return_value=df)

    result = compute_chart_analysis("AAPL", "equity", interval="1d")

    assert result["trend"] == "Bearish"
    assert result["signal"] == "Sell"
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd analytics && .venv/bin/pytest tests/test_chart_analysis.py -v`
Expected: FAIL with `ModuleNotFoundError: No module named 'analysis'`

- [ ] **Step 3: Write the implementation**

Create `analytics/analysis/__init__.py` as an empty file (makes `analysis`
a package).

```python
# analytics/analysis/chart_analysis.py
import pandas as pd
import pandas_ta as ta

from data.cache import fetch_ohlcv_cached
from strategies.method_714.smc import compute_swing_pivots, compute_structure


def compute_chart_analysis(symbol: str, asset_class: str, interval: str = "1d") -> dict:
    end_date = pd.Timestamp.utcnow().strftime("%Y-%m-%d")
    start_date = (pd.Timestamp.utcnow() - pd.Timedelta(days=180)).strftime("%Y-%m-%d")

    df = fetch_ohlcv_cached(symbol, asset_class, start_date, end_date, interval=interval)

    ema_fast = ta.ema(df["close"], length=20)
    ema_slow = ta.ema(df["close"], length=50)
    if ema_fast.iloc[-1] > ema_slow.iloc[-1]:
        trend = "Bullish"
    elif ema_fast.iloc[-1] < ema_slow.iloc[-1]:
        trend = "Bearish"
    else:
        trend = "Neutral"

    pivots = compute_swing_pivots(df)
    structure = compute_structure(pivots)
    structure_dir = int(structure["structure_dir"].iloc[-1])

    supports = [f"{v:.2f}" for v in structure["swing_low"].dropna().tail(2).tolist()]
    resistances = [f"{v:.2f}" for v in structure["swing_high"].dropna().tail(2).tolist()]

    if trend == "Bullish" and structure_dir == 1:
        signal, confidence = "Buy", 80
    elif trend == "Bearish" and structure_dir == -1:
        signal, confidence = "Sell", 80
    elif structure_dir == 0:
        signal, confidence = "Hold", 20
    else:
        signal, confidence = "Hold", 40

    # Most recent structure event (CHoCH takes priority over BOS if both
    # exist, since a CHoCH is the more recent-relevant regime change) --
    # matching compute_structure's own bos/choch semantics exactly.
    bos_idx = structure.index[structure["bos"]]
    choch_idx = structure.index[structure["choch"]]
    last_bos_ts = bos_idx[-1] if len(bos_idx) else None
    last_choch_ts = choch_idx[-1] if len(choch_idx) else None

    if last_choch_ts is not None and (last_bos_ts is None or last_choch_ts > last_bos_ts):
        row = structure.loc[last_choch_ts]
        direction = "Bullish" if row["bull_break"] else "Bearish"
        pattern = f"{direction} Change of Character"
    elif last_bos_ts is not None:
        row = structure.loc[last_bos_ts]
        direction = "Bullish" if row["bull_break"] else "Bearish"
        pattern = f"{direction} Break of Structure"
    else:
        pattern = "No recent structure break"

    structure_word = "bullish" if structure_dir == 1 else "bearish" if structure_dir == -1 else "neutral"
    summary = (
        f"{trend} trend with {structure_word} structure on {symbol} ({interval}). "
        "Confidence is a lightweight trend/structure agreement score, not a "
        "backtested strategy confidence like 714 Method's."
    )

    return {
        "signal": signal,
        "confidence": confidence,
        "trend": trend,
        "patterns": [pattern],
        "supports": supports,
        "resistances": resistances,
        "summary": summary,
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd analytics && .venv/bin/pytest tests/test_chart_analysis.py -v`
Expected: PASS (2 tests)

- [ ] **Step 5: Commit**

```bash
git add analytics/analysis/__init__.py analytics/analysis/chart_analysis.py analytics/tests/test_chart_analysis.py
git commit -m "feat(chart-analysis): compute_chart_analysis reusing the SMC swing-pivot engine"
```

---

### Task 2: `POST /chart-analysis` endpoint

**Files:**
- Modify: `analytics/schemas.py`
- Modify: `analytics/main.py`
- Test: `analytics/tests/test_chart_analysis_endpoint.py`

**Interfaces:**
- Consumes: `compute_chart_analysis(symbol, asset_class, interval)` from Task 1.
- Produces: `POST /chart-analysis` accepting `{"symbol": str, "asset_class": AssetClass, "interval": str = "1d"}`, returning the `dict` shape from Task 1 — Task 3's `AnalyticsServiceClient::analyzeChart` relies on this exact route path and JSON shape.

- [ ] **Step 1: Write the failing test**

```python
# analytics/tests/test_chart_analysis_endpoint.py
import pandas as pd
from fastapi.testclient import TestClient
from main import app

client = TestClient(app)


def _bullish_df():
    idx = pd.date_range("2023-01-01", periods=120, freq="D", tz="UTC")
    flat = [100.0 + (i % 3) * 0.1 for i in range(60)]
    uptrend = [100.0 + i * 1.5 for i in range(1, 61)]
    close = pd.Series(flat + uptrend, index=idx)
    high = close + 0.5
    low = close - 0.5
    return pd.DataFrame({"open": close, "high": high, "low": low, "close": close, "volume": 1000})


def test_chart_analysis_returns_computed_result(mocker):
    # Patched where fetch_ohlcv_cached is actually looked up at call time --
    # chart_analysis.py's own module namespace, not main.py's (main.py
    # never calls it directly; compute_chart_analysis does).
    mocker.patch("analysis.chart_analysis.fetch_ohlcv_cached", return_value=_bullish_df())

    response = client.post(
        "/chart-analysis",
        json={"symbol": "AAPL", "asset_class": "equity", "interval": "1d"},
    )

    assert response.status_code == 200
    body = response.json()
    assert body["trend"] == "Bullish"
    assert body["signal"] == "Buy"
    assert "confidence" in body
    assert "supports" in body
    assert "resistances" in body


def test_chart_analysis_returns_422_on_fetch_failure(mocker):
    from data.fetch import DataFetchError

    mocker.patch(
        "analysis.chart_analysis.fetch_ohlcv_cached",
        side_effect=DataFetchError("No equity data for symbol 'BADSYMBOL'"),
    )

    response = client.post(
        "/chart-analysis",
        json={"symbol": "BADSYMBOL", "asset_class": "equity", "interval": "1d"},
    )

    assert response.status_code == 422
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd analytics && .venv/bin/pytest tests/test_chart_analysis_endpoint.py -v`
Expected: FAIL with 404 (no `/chart-analysis` route exists yet)

- [ ] **Step 3: Write the implementation**

In `analytics/schemas.py`, append:

```python
class ChartAnalysisRequest(BaseModel):
    symbol: str
    asset_class: AssetClass
    interval: str = "1d"
```

In `analytics/main.py`, change the imports:

```python
from schemas import BacktestRequest, BacktestResult
from data.cache import fetch_ohlcv_cached
from data.fetch import DataFetchError
```

to:

```python
from schemas import BacktestRequest, BacktestResult, ChartAnalysisRequest
from data.cache import fetch_ohlcv_cached
from data.fetch import DataFetchError
from analysis.chart_analysis import compute_chart_analysis
```

Then append this route at the end of `main.py`:

```python
@app.post("/chart-analysis")
def chart_analysis(request: ChartAnalysisRequest):
    try:
        return compute_chart_analysis(request.symbol, request.asset_class, request.interval)
    except DataFetchError as exc:
        raise HTTPException(status_code=422, detail=str(exc))
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd analytics && .venv/bin/pytest tests/test_chart_analysis_endpoint.py -v`
Expected: PASS (2 tests)

- [ ] **Step 5: Run the full analytics suite**

Run: `cd analytics && .venv/bin/pytest -v`
Expected: All tests pass, no regressions.

- [ ] **Step 6: Commit**

```bash
git add analytics/schemas.py analytics/main.py analytics/tests/test_chart_analysis_endpoint.py
git commit -m "feat(chart-analysis): add POST /chart-analysis endpoint"
```

---

### Task 3: Laravel wiring — `AnalyticsServiceClient::analyzeChart` + `ChartAnalysisController`

**Files:**
- Modify: `backend/app/Services/AnalyticsServiceClient.php`
- Modify: `backend/app/Http/Controllers/ChartAnalysisController.php`
- Test: `backend/tests/Feature/ChartAnalysisTest.php`

**Interfaces:**
- Consumes: `POST /chart-analysis` from Task 2, exact request/response shape.
- Produces: nothing new for later tasks — this is the final backend task.

- [ ] **Step 1: Write the failing tests**

Append to `backend/tests/Feature/ChartAnalysisTest.php`, inside the class (add `use App\Services\AnalyticsServiceClient;` is not needed — `Http::fake` is already imported at the top via `use Illuminate\Support\Facades\Http;`, confirm this import exists; if not, add it):

```php
    public function test_analyze_chart_with_symbol_override_returns_real_analysis(): void
    {
        Http::fake([
            '*/chart-analysis' => Http::response([
                'signal' => 'Buy',
                'confidence' => 80,
                'trend' => 'Bullish',
                'patterns' => ['Bullish Break of Structure'],
                'supports' => ['148.20', '145.10'],
                'resistances' => ['152.30', '155.00'],
                'summary' => 'Bullish trend with bullish structure on AAPL (1d).',
            ], 200),
        ]);

        $response = $this->postJson('/api/chart/analyze', [
            'image' => self::TINY_PNG_BASE64,
            'market' => 'stocks',
            'symbol' => 'AAPL',
        ]);

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'is_demo' => false,
            'symbol_detected' => 'AAPL',
        ]);
        $response->assertJsonPath('analysis.signal', 'Buy');
        $response->assertJsonPath('analysis.trend', 'Bullish');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/chart-analysis')
                && $request['symbol'] === 'AAPL'
                && $request['asset_class'] === 'equity';
        });
    }

    public function test_analyze_chart_falls_back_to_placeholder_when_analytics_service_fails(): void
    {
        Http::fake([
            '*/chart-analysis' => Http::response(['detail' => 'No equity data for symbol \'BADSYMBOL\''], 422),
        ]);

        $response = $this->postJson('/api/chart/analyze', [
            'image' => self::TINY_PNG_BASE64,
            'market' => 'stocks',
            'symbol' => 'BADSYMBOL',
        ]);

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'is_demo' => true,
        ]);
        $response->assertJsonPath('analysis.summary', 'Placeholder analysis — not computed from the uploaded chart or live market data.');
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd backend && php artisan test --filter=ChartAnalysisTest`
Expected: The 2 new tests FAIL — `test_analyze_chart_with_symbol_override_returns_real_analysis` fails because `symbol` isn't a validated field yet (422 on unexpected extra input is not the issue since Laravel ignores unvalidated extra fields by default, but the controller never reads or uses it, so `is_demo` stays `true`, not `false`, and no `/chart-analysis` call is ever sent — `Http::assertSent` fails). The 4 pre-existing tests still PASS.

- [ ] **Step 3: Write the implementation**

In `backend/app/Services/AnalyticsServiceClient.php`, append this method inside the class, after `runBacktest`:

```php
    /**
     * @param array $payload matches the Python service's ChartAnalysisRequest shape
     * @return array the decoded JSON response (signal/confidence/trend/patterns/supports/resistances/summary)
     * @throws RuntimeException on a non-2xx response or connection failure
     */
    public function analyzeChart(array $payload): array
    {
        $response = Http::timeout(30)->post("{$this->baseUrl}/chart-analysis", $payload);

        if ($response->failed()) {
            throw new RuntimeException($this->errorMessage($response));
        }

        return $response->json();
    }
```

Replace the full contents of `backend/app/Http/Controllers/ChartAnalysisController.php`:

```php
<?php
namespace App\Http\Controllers;

use App\Services\AnalyticsServiceClient;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class ChartAnalysisController extends Controller
{
    private const MARKET_TO_ASSET_CLASS = [
        'stocks' => 'equity',
        'crypto' => 'crypto',
        'forex' => 'forex',
    ];

    public function __construct(
        private readonly AnalyticsServiceClient $analyticsClient,
    ) {
    }

    /**
     * Analyze chart and detect symbol.
     *
     * Real analysis: when a symbol is known — either the caller supplies
     * one directly, or OCR against the uploaded image finds one — this
     * fetches real recent market data for that symbol and computes real
     * trend/structure/support-resistance analysis (see
     * analytics/analysis/chart_analysis.py). When no symbol is known, or
     * the analytics service call fails (e.g. a bad OCR guess that isn't a
     * real ticker), this falls back to a fixed, clearly-labeled placeholder
     * response — it never presents fake numbers as if they were real.
     */
    public function analyzeChart(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'image' => 'required|string',
            'market' => 'required|in:stocks,crypto,forex',
            'additional_context' => 'nullable|string',
            'symbol' => 'nullable|string|max:20',
        ]);

        $image = $validated['image'];
        $market = $validated['market'];
        $symbol = $validated['symbol'] ?? $this->detectSymbolFromImage($image);

        if ($symbol !== null) {
            $assetClass = self::MARKET_TO_ASSET_CLASS[$market];

            try {
                $analysis = $this->analyticsClient->analyzeChart([
                    'symbol' => $symbol,
                    'asset_class' => $assetClass,
                    'interval' => '1d',
                ]);

                return response()->json([
                    'success' => true,
                    'is_demo' => false,
                    'disclaimer' => 'Computed from real recent market data for the detected symbol using '
                        . 'swing-structure analysis. This is not a backtested trading strategy signal and '
                        . 'must not be used to make trading decisions.',
                    'analysis' => $analysis,
                    'symbol_detected' => $symbol,
                    'market' => $market,
                ]);
            } catch (RuntimeException) {
                // Falls through to the placeholder below — a bad OCR guess
                // or a transient analytics-service failure must not turn
                // into a hard error for the user.
            }
        }

        return $this->placeholderResponse($symbol, $market);
    }

    private function placeholderResponse(?string $symbol, string $market): JsonResponse
    {
        // Placeholder analysis. Not derived from the uploaded chart, live
        // market data, or any statistical/backtesting service. Do not wire
        // this into anything that presents itself as real trading advice
        // without replacing this block first.
        $analysis = [
            'signal' => 'Buy',
            'confidence' => 85,
            'trend' => 'Bullish',
            'patterns' => ['Ascending Triangle'],
            'supports' => ['48000', '47500'],
            'resistances' => ['49500', '50000'],
            'summary' => 'Placeholder analysis — not computed from the uploaded chart or live market data.',
        ];

        return response()->json([
            'success' => true,
            'is_demo' => true,
            'disclaimer' => 'This is a placeholder/demo result for UI development only. It is not generated from your chart, real market data, or any trading model, and must not be used to make trading decisions.',
            'analysis' => $analysis,
            'symbol_detected' => $symbol,
            'market' => $market,
        ]);
    }

    protected function detectSymbolFromImage($base64Image)
    {
        $imageData = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $base64Image));
        $tmpFile = tempnam(sys_get_temp_dir(), 'chart_');
        file_put_contents($tmpFile, $imageData);
        $outputFile = $tmpFile . '_out';
        $cmd = "tesseract $tmpFile $outputFile -l eng --oem 1 --psm 6";
        exec($cmd);
        $text = @file_get_contents($outputFile . '.txt');
        unlink($tmpFile);
        unlink($outputFile . '.txt');
        if (!$text) return null;
        if (preg_match('/\b([A-Z]{2,5})\b/', $text, $matches)) {
            return $matches[1];
        }
        return null;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd backend && php artisan test --filter=ChartAnalysisTest`
Expected: PASS (all 6 tests — 4 pre-existing + 2 new)

- [ ] **Step 5: Run the full Laravel suite**

Run: `cd backend && php artisan test`
Expected: All tests pass, no regressions.

- [ ] **Step 6: Commit**

```bash
git add backend/app/Services/AnalyticsServiceClient.php backend/app/Http/Controllers/ChartAnalysisController.php backend/tests/Feature/ChartAnalysisTest.php
git commit -m "feat(chart-analysis): wire real analysis through ChartAnalysisController with placeholder fallback"
```

---

### Task 4: Frontend symbol override input + manual verification

**Files:**
- Modify: `frontend/index.html`
- Modify: `frontend/src/main.js`

**Interfaces:**
- Consumes: the `symbol` request field from Task 3 (must be sent as `symbol` in the JSON body posted to `/api/chart/analyze`).
- Produces: nothing new — final task in the plan.

**Context:** `index.html`'s upload flow currently sends only `image` and a hardcoded `market: 'crypto'` — there is no UI for a symbol or market at all, so the override this slice adds is otherwise unreachable except via direct API calls. Add a minimal optional symbol text input so the feature can actually be used and manually verified end-to-end.

- [ ] **Step 1: Add a symbol input to `index.html`**

Find the upload area section (containing `<input type="file" id="fileInput" accept="image/*" hidden>`) and add, immediately after the closing tag of that upload area's containing element:

```html
<div style="margin-top:12px;text-align:center">
  <label for="symbolOverride" style="font-size:14px;color:var(--muted)">Symbol (optional, improves accuracy over OCR)</label><br>
  <input id="symbolOverride" placeholder="AAPL" style="margin-top:6px" />
</div>
```

- [ ] **Step 2: Send it from `main.js`**

In `frontend/src/main.js`, change:

```javascript
        const response = await fetch('http://localhost:8000/api/chart/analyze', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            image: previewSrc,
            market: 'crypto'
          })
        });
```

to:

```javascript
        const symbolOverride = document.getElementById('symbolOverride')?.value.trim();
        const response = await fetch('http://localhost:8000/api/chart/analyze', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            image: previewSrc,
            market: 'crypto',
            ...(symbolOverride ? { symbol: symbolOverride } : {}),
          })
        });
```

- [ ] **Step 3: Manual verification**

1. Start the three dev servers: analytics (`cd analytics && .venv/bin/uvicorn main:app --port 8001`), backend (`chartsense-backend` launch config, port 8000), frontend (`chartsense` launch config, port 3000).
2. Open `http://localhost:3000/index.html`, type `BTC-USD` into the new "Symbol" field (crypto market is hardcoded, so use a symbol yfinance/ccxt can actually resolve for `crypto` — check `data/fetch.py`'s `_fetch_crypto` expects a ccxt-style pair like `BTC/USDT`; type `BTC/USDT`), then upload any small image file.
3. Confirm the result panel shows `is_demo: false`-driven content (no "placeholder" disclaimer text) with real-looking `signal`/`trend`/`supports`/`resistances` values, and no console errors.
4. Clear the symbol field, upload the same image again, and confirm it falls back to the placeholder response (OCR won't find a real ticker in an arbitrary image) with the original placeholder disclaimer text — proving the fallback path still works end-to-end.
5. Stop the dev servers.

- [ ] **Step 4: Commit**

```bash
git add frontend/index.html frontend/src/main.js
git commit -m "feat(chart-analysis): add optional symbol override input to the upload UI"
```
