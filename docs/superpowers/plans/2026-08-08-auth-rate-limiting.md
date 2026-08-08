# Authentication, Rate Limiting & User Ownership Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add real Laravel Sanctum authentication (register/login/logout/me), per-endpoint rate limiting (stricter for anonymous callers than authenticated ones on the expensive `/api/backtests` endpoint), and make `backtest_runs.user_id` actually populate for authenticated users — the column has existed since the first backtesting slice but has always been null because no auth existed.

**Architecture:** Laravel Sanctum in token mode (bearer tokens, not cookie-based SPA auth — frontend and backend are separate origins). A new `AuthController` handles register/login/logout/me. Named rate limiters (`RateLimiter::for(...)`) key by user ID when authenticated, by IP when not. `BacktestController` needs no logic change — its `user_id` line was already written correctly in anticipation of this slice; only the route-level `auth` middleware context changes. Frontend gets two new pages (`login.html`, `register.html`) and a shared `auth.js` module that the existing `backtest.js` uses to attach a bearer token when one exists.

**Tech Stack:** Laravel 12 / PHP 8.2, Laravel Sanctum (new dependency), same vanilla JS/Vite frontend as the rest of the app.

## Global Constraints

- Sanctum runs in **token mode**, not SPA cookie mode — no `EnsureFrontendRequestsAreStateful` middleware, no CORS/session config changes needed.
- Anonymous backtests remain allowed (product decision) — this slice must not wall off `/api/backtests` behind a login requirement. Every test that exercises the anonymous path must keep passing.
- Rate limits: `/api/backtests` — 3/hour anonymous (by IP), 30/hour authenticated (by user ID). `/api/chart/analyze` — 10/hour by IP, no auth required.
- Email verification and password reset are explicitly out of scope — do not build partial/stubbed versions of either.
- No live-network calls in tests. Sanctum token creation and Laravel's rate limiter are both testable in-process.
- Login must return `401` with a generic message on bad credentials (not `422`, and not a message that reveals whether the email exists).

---

## File Structure

```
ChartSense/
└── backend/
    ├── composer.json                             # MODIFY — adds laravel/sanctum
    ├── app/Models/User.php                        # MODIFY — HasApiTokens trait
    ├── app/Http/Controllers/AuthController.php     # NEW — register/login/logout/me
    ├── app/Providers/AppServiceProvider.php        # MODIFY — RateLimiter::for(...) definitions
    ├── routes/api.php                              # MODIFY — auth routes + throttle middleware
    └── tests/Feature/
        ├── AuthControllerTest.php                  # NEW
        └── BacktestControllerTest.php               # MODIFY — user_id + rate-limit regression cases
└── frontend/
    ├── src/auth.js                                 # NEW — token storage helpers
    ├── login.html                                  # NEW
    ├── register.html                                # NEW
    ├── src/backtest.js                              # MODIFY — send bearer token when present
    ├── backtest.html                                # MODIFY — auth-state header link
    └── index.html                                   # MODIFY — auth-state header link
```

---

### Task 1: Install Sanctum + `AuthController::register`

**Files:**
- Modify: `backend/composer.json` (via `composer require`)
- Modify: `backend/app/Models/User.php`
- Create: `backend/app/Http/Controllers/AuthController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/AuthControllerTest.php`

**Interfaces:**
- Consumes: nothing new.
- Produces: `POST /api/register` — the wire contract (`{name, email, password}` → `{success, token, user}`) every later task in this plan builds on. `AuthController` class, extended by Tasks 2–3.

- [ ] **Step 1: Install Sanctum and run its migration**

```bash
cd /Users/sakhilebhayi/Dot/ChartSense/backend
composer require laravel/sanctum
php artisan migrate
```

Expected: a `personal_access_tokens` table migration runs (Sanctum's service provider auto-loads its own migrations — no `vendor:publish` needed for token-mode usage).

- [ ] **Step 2: Add `HasApiTokens` to the User model**

```php
<?php
// backend/app/Models/User.php — add the import and trait use

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    // ... rest of the class unchanged
}
```

- [ ] **Step 3: Write the failing test**

```php
<?php
// backend/tests/Feature/AuthControllerTest.php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_creates_a_user_and_returns_a_token(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'correct-horse-battery-staple',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('success', true);
        $response->assertJsonStructure(['token', 'user' => ['id', 'name', 'email']]);

        $this->assertDatabaseHas('users', [
            'email' => 'ada@example.com',
            'name' => 'Ada Lovelace',
        ]);

        $user = User::where('email', 'ada@example.com')->first();
        $this->assertNotSame('correct-horse-battery-staple', $user->password, 'password must be hashed');
    }

    public function test_register_rejects_duplicate_email(): void
    {
        User::factory()->create(['email' => 'ada@example.com']);

        $response = $this->postJson('/api/register', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'correct-horse-battery-staple',
        ]);

        $response->assertStatus(422);
    }

    public function test_register_requires_all_fields(): void
    {
        $response = $this->postJson('/api/register', ['email' => 'ada@example.com']);

        $response->assertStatus(422);
    }
}
```

- [ ] **Step 4: Run test to verify it fails**

Run: `php artisan test --filter=AuthControllerTest`
Expected: FAIL — route `POST /api/register` does not exist (`404`)

- [ ] **Step 5: Write minimal implementation**

```php
<?php
// backend/app/Http/Controllers/AuthController.php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email',
            'password' => 'required|string|min:8',
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
        ]);

        $token = $user->createToken('api')->plainTextToken;

        return response()->json([
            'success' => true,
            'token' => $token,
            'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
        ], 201);
    }
}
```

```php
<?php
// backend/routes/api.php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BacktestController;
use App\Http\Controllers\ChartAnalysisController;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register']);

Route::post('/chart/analyze', [ChartAnalysisController::class, 'analyzeChart']);
Route::post('/backtests', [BacktestController::class, 'store']);
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --filter=AuthControllerTest`
Expected: PASS (3 tests)

- [ ] **Step 7: Commit**

```bash
cd /Users/sakhilebhayi/Dot/ChartSense
git add backend/composer.json backend/composer.lock backend/app/Models/User.php \
        backend/app/Http/Controllers/AuthController.php backend/routes/api.php \
        backend/tests/Feature/AuthControllerTest.php backend/database/migrations
git commit -m "feat(backend): add Sanctum and POST /api/register"
```

---

### Task 2: `AuthController::login`

**Files:**
- Modify: `backend/app/Http/Controllers/AuthController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/AuthControllerTest.php`

**Interfaces:**
- Consumes: `App\Models\User` (Task 1), Sanctum's `createToken` (Task 1).
- Produces: `POST /api/login` — `{email, password}` → `{success, token, user}` on success, `401` on bad credentials. Used by Task 6 (frontend login page).

- [ ] **Step 1: Write the failing tests**

```php
// backend/tests/Feature/AuthControllerTest.php — add these two tests to the class

public function test_login_returns_a_token_for_valid_credentials(): void
{
    User::factory()->create([
        'email' => 'ada@example.com',
        'password' => bcrypt('correct-horse-battery-staple'),
    ]);

    $response = $this->postJson('/api/login', [
        'email' => 'ada@example.com',
        'password' => 'correct-horse-battery-staple',
    ]);

    $response->assertOk();
    $response->assertJsonPath('success', true);
    $response->assertJsonStructure(['token', 'user' => ['id', 'name', 'email']]);
}

public function test_login_rejects_wrong_password_with_401(): void
{
    User::factory()->create([
        'email' => 'ada@example.com',
        'password' => bcrypt('correct-horse-battery-staple'),
    ]);

    $response = $this->postJson('/api/login', [
        'email' => 'ada@example.com',
        'password' => 'wrong-password',
    ]);

    $response->assertStatus(401);
    $response->assertJsonPath('success', false);
}

public function test_login_rejects_unknown_email_with_401(): void
{
    $response = $this->postJson('/api/login', [
        'email' => 'nobody@example.com',
        'password' => 'whatever-it-is',
    ]);

    $response->assertStatus(401);
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=AuthControllerTest`
Expected: FAIL — route `POST /api/login` does not exist (`404`)

- [ ] **Step 3: Write minimal implementation**

```php
// backend/app/Http/Controllers/AuthController.php — add this method to the class

public function login(Request $request): JsonResponse
{
    $validated = $request->validate([
        'email' => 'required|email',
        'password' => 'required|string',
    ]);

    $user = User::where('email', $validated['email'])->first();

    if (! $user || ! Hash::check($validated['password'], $user->password)) {
        return response()->json([
            'success' => false,
            'message' => 'These credentials do not match our records.',
        ], 401);
    }

    $token = $user->createToken('api')->plainTextToken;

    return response()->json([
        'success' => true,
        'token' => $token,
        'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
    ]);
}
```

```php
// backend/routes/api.php — add alongside the register route
Route::post('/login', [AuthController::class, 'login']);
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=AuthControllerTest`
Expected: PASS (6 tests)

- [ ] **Step 5: Commit**

```bash
cd /Users/sakhilebhayi/Dot/ChartSense
git add backend/app/Http/Controllers/AuthController.php backend/routes/api.php \
        backend/tests/Feature/AuthControllerTest.php
git commit -m "feat(backend): add POST /api/login"
```

---

### Task 3: `AuthController::logout` + `me`, `auth:sanctum` middleware

**Files:**
- Modify: `backend/app/Http/Controllers/AuthController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/AuthControllerTest.php`

**Interfaces:**
- Consumes: Sanctum's `auth:sanctum` guard middleware (built into the package, no custom code).
- Produces: `POST /api/logout` (auth required), `GET /api/me` (auth required) — used by Task 6/7 (frontend auth-state header).

- [ ] **Step 1: Write the failing tests**

```php
// backend/tests/Feature/AuthControllerTest.php — add these to the class

public function test_me_returns_current_user_when_authenticated(): void
{
    $user = User::factory()->create(['email' => 'ada@example.com']);
    $token = $user->createToken('api')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/me');

    $response->assertOk();
    $response->assertJsonPath('email', 'ada@example.com');
}

public function test_me_returns_401_when_not_authenticated(): void
{
    $response = $this->getJson('/api/me');

    $response->assertStatus(401);
}

public function test_logout_invalidates_the_token(): void
{
    $user = User::factory()->create();
    $token = $user->createToken('api')->plainTextToken;

    $logoutResponse = $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/logout');
    $logoutResponse->assertOk();

    $meResponse = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/me');
    $meResponse->assertStatus(401);
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=AuthControllerTest`
Expected: FAIL — routes `POST /api/logout` and `GET /api/me` don't exist (`404`)

- [ ] **Step 3: Write minimal implementation**

```php
// backend/app/Http/Controllers/AuthController.php — add these two methods

public function logout(Request $request): JsonResponse
{
    $request->user()->currentAccessToken()->delete();

    return response()->json(['success' => true]);
}

public function me(Request $request): JsonResponse
{
    $user = $request->user();

    return response()->json([
        'id' => $user->id,
        'name' => $user->name,
        'email' => $user->email,
    ]);
}
```

```php
// backend/routes/api.php — replace the two standalone auth routes with this group,
// keep register/login as they are

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
});
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=AuthControllerTest`
Expected: PASS (9 tests)

- [ ] **Step 5: Commit**

```bash
cd /Users/sakhilebhayi/Dot/ChartSense
git add backend/app/Http/Controllers/AuthController.php backend/routes/api.php \
        backend/tests/Feature/AuthControllerTest.php
git commit -m "feat(backend): add POST /api/logout and GET /api/me"
```

---

### Task 4: Rate limiting

**Files:**
- Modify: `backend/app/Providers/AppServiceProvider.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/BacktestControllerTest.php`
- Test: `backend/tests/Feature/ChartAnalysisTest.php`

**Interfaces:**
- Consumes: Laravel's built-in `RateLimiter` facade and `throttle` middleware — no new dependency.
- Produces: named limiters `backtests` and `chart-analysis`, applied at the route level. No function signatures for later tasks to consume — this is the last backend task in this plan.

- [ ] **Step 1: Write the failing tests**

```php
// backend/tests/Feature/BacktestControllerTest.php — add these two tests to the class

public function test_anonymous_backtests_are_capped_at_three_per_hour(): void
{
    Http::fake(['*/backtest' => Http::response(['metrics' => ['trade_count' => 0]], 200)]);

    $payload = [
        'symbol' => 'AAPL',
        'asset_class' => 'equity',
        'strategy' => 'ma_crossover',
        'start_date' => '2023-01-01',
        'end_date' => '2026-01-01',
    ];

    for ($i = 0; $i < 3; $i++) {
        $this->postJson('/api/backtests', $payload)->assertStatus(fn ($status) => $status !== 429);
    }

    $this->postJson('/api/backtests', $payload)->assertStatus(429);
}

public function test_authenticated_backtests_have_a_higher_limit_than_anonymous(): void
{
    Http::fake(['*/backtest' => Http::response(['metrics' => ['trade_count' => 0]], 200)]);

    $user = \App\Models\User::factory()->create();
    $token = $user->createToken('api')->plainTextToken;

    $payload = [
        'symbol' => 'AAPL',
        'asset_class' => 'equity',
        'strategy' => 'ma_crossover',
        'start_date' => '2023-01-01',
        'end_date' => '2026-01-01',
    ];

    // 4 requests would 429 an anonymous caller (limit is 3/hr) — an
    // authenticated user must still succeed, proving the limits differ.
    for ($i = 0; $i < 4; $i++) {
        $response = $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/backtests', $payload);
        $this->assertNotEquals(429, $response->status());
    }
}
```

```php
// backend/tests/Feature/ChartAnalysisTest.php — add this test to the existing class

public function test_chart_analyze_is_rate_limited_at_ten_per_hour(): void
{
    for ($i = 0; $i < 10; $i++) {
        $this->postJson('/api/chart/analyze', [
            'image' => self::TINY_PNG_BASE64,
            'market' => 'crypto',
        ])->assertStatus(fn ($status) => $status !== 429);
    }

    $response = $this->postJson('/api/chart/analyze', [
        'image' => self::TINY_PNG_BASE64,
        'market' => 'crypto',
    ]);

    $response->assertStatus(429);
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=BacktestControllerTest`
Run: `php artisan test --filter=ChartAnalysisTest`
Expected: FAIL — no rate limit is enforced yet, so the final request in each test still returns its normal status instead of `429`

- [ ] **Step 3: Write minimal implementation**

```php
<?php
// backend/app/Providers/AppServiceProvider.php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Anonymous callers get a much tighter cap than authenticated users
        // on this endpoint specifically — it triggers a real, live
        // yfinance/ccxt call every time, so it's the platform's main
        // cost/abuse surface.
        RateLimiter::for('backtests', function (Request $request) {
            return $request->user()
                ? Limit::perHour(30)->by('backtests:user:'.$request->user()->id)
                : Limit::perHour(3)->by('backtests:ip:'.$request->ip());
        });

        // Unauthenticated by design (matches the endpoint's current
        // no-auth reality) — still bounded because the OCR shell-out to
        // tesseract is a real local resource cost.
        RateLimiter::for('chart-analysis', function (Request $request) {
            return Limit::perHour(10)->by('chart-analysis:ip:'.$request->ip());
        });
    }
}
```

```php
<?php
// backend/routes/api.php — final version, apply throttle to the two expensive routes

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BacktestController;
use App\Http\Controllers\ChartAnalysisController;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
});

Route::post('/chart/analyze', [ChartAnalysisController::class, 'analyzeChart'])
    ->middleware('throttle:chart-analysis');
Route::post('/backtests', [BacktestController::class, 'store'])
    ->middleware('throttle:backtests');
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=BacktestControllerTest`
Run: `php artisan test --filter=ChartAnalysisTest`
Expected: PASS

- [ ] **Step 5: Run the full Laravel test suite**

Run: `php artisan test`
Expected: all tests pass — this is the point where a still-anonymous-friendly `/api/backtests` regression would show up (an earlier test in this same file posts without auth and expects success; if that starts failing, the throttle or route change broke anonymous access)

- [ ] **Step 6: Commit**

```bash
cd /Users/sakhilebhayi/Dot/ChartSense
git add backend/app/Providers/AppServiceProvider.php backend/routes/api.php \
        backend/tests/Feature/BacktestControllerTest.php backend/tests/Feature/ChartAnalysisTest.php
git commit -m "feat(backend): add per-endpoint rate limiting (anonymous vs authenticated)"
```

---

### Task 5: `backtest_runs.user_id` regression test

**Files:**
- Test: `backend/tests/Feature/BacktestControllerTest.php`

**Interfaces:**
- Consumes: `AuthController` (Tasks 1–3), `BacktestController::store`'s existing `'user_id' => $request->user()?->id` line (unchanged — this task is pure verification that the earlier speculative code now does something).
- Produces: nothing new — this is a regression-test-only task confirming the whole slice's actual point (real user ownership) works end to end.

- [ ] **Step 1: Write the test**

```php
// backend/tests/Feature/BacktestControllerTest.php — add to the class

public function test_authenticated_backtest_run_is_owned_by_the_user(): void
{
    Http::fake([
        '*/backtest' => Http::response([
            'symbol' => 'AAPL',
            'asset_class' => 'equity',
            'strategy' => 'ma_crossover',
            'params' => [],
            'start_date' => '2023-01-01',
            'end_date' => '2026-01-01',
            'metrics' => [
                'total_return_pct' => 1.0, 'win_rate_pct' => 50.0, 'max_drawdown_pct' => -1.0,
                'sharpe_ratio' => 0.5, 'trade_count' => 12, 'losing_trade_count' => 6,
            ],
            'equity_curve' => [],
            'trades' => [],
        ], 200),
    ]);

    $user = \App\Models\User::factory()->create();
    $token = $user->createToken('api')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/backtests', [
        'symbol' => 'AAPL',
        'asset_class' => 'equity',
        'strategy' => 'ma_crossover',
        'start_date' => '2023-01-01',
        'end_date' => '2026-01-01',
    ])->assertOk();

    $this->assertDatabaseHas('backtest_runs', [
        'symbol' => 'AAPL',
        'user_id' => $user->id,
    ]);
}
```

- [ ] **Step 2: Run the test**

Run: `php artisan test --filter=test_authenticated_backtest_run_is_owned_by_the_user`
Expected: PASS immediately — no implementation step needed, since `BacktestController::store`'s `'user_id' => $request->user()?->id` line was already written correctly in the original backtesting slice, anticipating this one. This test exists to prove that claim rather than take it on faith.

- [ ] **Step 3: Commit**

```bash
cd /Users/sakhilebhayi/Dot/ChartSense
git add backend/tests/Feature/BacktestControllerTest.php
git commit -m "test(backend): verify backtest_runs.user_id is populated for authenticated requests"
```

---

### Task 6: Frontend — `auth.js`, `login.html`, `register.html`

**Files:**
- Create: `frontend/src/auth.js`
- Create: `frontend/login.html`
- Create: `frontend/register.html`
- Modify: `frontend/vite.config.js`

**Interfaces:**
- Consumes: `POST /api/register`, `POST /api/login` (Tasks 1–2).
- Produces: `auth.js` exports `getToken()`, `setToken(token)`, `clearToken()`, `isLoggedIn()` — used by Task 7.

**Note on testing:** matches the existing project convention (no JS test framework) — verification is manual, in the browser.

- [ ] **Step 1: Create the shared auth module**

```js
// frontend/src/auth.js
const STORAGE_KEY = 'chartsense_token';

export function getToken() {
  return localStorage.getItem(STORAGE_KEY);
}

export function setToken(token) {
  localStorage.setItem(STORAGE_KEY, token);
}

export function clearToken() {
  localStorage.removeItem(STORAGE_KEY);
}

export function isLoggedIn() {
  return getToken() !== null;
}
```

- [ ] **Step 2: Create the login page**

```html
<!-- frontend/login.html -->
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Dot.Charts — Log In</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png" />
  <style>
    :root {
      --bg:#020617;--panel:rgba(15,23,42,.7);--border:rgba(148,163,184,.15);
      --text:#e5e7eb;--muted:#94a3b8;--accent:#22d3ee;--red:#ef4444;
    }
    *{box-sizing:border-box;font-family:system-ui,-apple-system,BlinkMacSystemFont,sans-serif}
    body{margin:0;min-height:100vh;color:var(--text);background:var(--bg)}
    .container{max-width:420px;margin:0 auto;padding:64px 24px}
    h1{font-size:28px;margin-bottom:24px}
    .card{background:var(--panel);border:1px solid var(--border);border-radius:16px;padding:28px}
    label{display:block;font-size:13px;color:var(--muted);margin:14px 0 6px}
    input{width:100%;padding:10px 12px;border-radius:8px;border:1px solid var(--border);
      background:#0f172a;color:var(--text);font-size:15px}
    button{margin-top:22px;width:100%;padding:14px 22px;background:var(--accent);color:var(--bg);
      border:none;border-radius:10px;font-weight:700;cursor:pointer;font-size:15px}
    button:disabled{opacity:.5;cursor:not-allowed}
    #error{color:var(--red);margin-top:14px;display:none}
    .switch{margin-top:18px;font-size:14px;color:var(--muted);text-align:center}
    .switch a{color:var(--accent);text-decoration:none}
  </style>
</head>
<body>
<div class="container">
  <h1>Log In</h1>
  <div class="card">
    <label for="email">Email</label>
    <input id="email" type="email" placeholder="you@example.com" />
    <label for="password">Password</label>
    <input id="password" type="password" placeholder="••••••••" />
    <button id="loginButton">Log In</button>
    <div id="error"></div>
  </div>
  <p class="switch">No account? <a href="/register.html">Register</a></p>
</div>
<script type="module" src="/src/login.js"></script>
</body>
</html>
```

```js
// frontend/src/login.js
import { setToken } from './auth.js';

const API_BASE = 'http://localhost:8000/api';
const errorEl = document.getElementById('error');
const button = document.getElementById('loginButton');

button.addEventListener('click', async () => {
  errorEl.style.display = 'none';
  button.disabled = true;

  try {
    const response = await fetch(`${API_BASE}/login`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({
        email: document.getElementById('email').value.trim(),
        password: document.getElementById('password').value,
      }),
    });
    const body = await response.json();

    if (!response.ok || body.success === false) {
      throw new Error(body.message || 'Login failed');
    }

    setToken(body.token);
    window.location.href = '/backtest.html';
  } catch (err) {
    errorEl.textContent = err.message;
    errorEl.style.display = 'block';
  } finally {
    button.disabled = false;
  }
});
```

- [ ] **Step 3: Create the register page**

```html
<!-- frontend/register.html -->
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Dot.Charts — Register</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png" />
  <style>
    :root {
      --bg:#020617;--panel:rgba(15,23,42,.7);--border:rgba(148,163,184,.15);
      --text:#e5e7eb;--muted:#94a3b8;--accent:#22d3ee;--red:#ef4444;
    }
    *{box-sizing:border-box;font-family:system-ui,-apple-system,BlinkMacSystemFont,sans-serif}
    body{margin:0;min-height:100vh;color:var(--text);background:var(--bg)}
    .container{max-width:420px;margin:0 auto;padding:64px 24px}
    h1{font-size:28px;margin-bottom:24px}
    .card{background:var(--panel);border:1px solid var(--border);border-radius:16px;padding:28px}
    label{display:block;font-size:13px;color:var(--muted);margin:14px 0 6px}
    input{width:100%;padding:10px 12px;border-radius:8px;border:1px solid var(--border);
      background:#0f172a;color:var(--text);font-size:15px}
    button{margin-top:22px;width:100%;padding:14px 22px;background:var(--accent);color:var(--bg);
      border:none;border-radius:10px;font-weight:700;cursor:pointer;font-size:15px}
    button:disabled{opacity:.5;cursor:not-allowed}
    #error{color:var(--red);margin-top:14px;display:none}
    .switch{margin-top:18px;font-size:14px;color:var(--muted);text-align:center}
    .switch a{color:var(--accent);text-decoration:none}
  </style>
</head>
<body>
<div class="container">
  <h1>Register</h1>
  <div class="card">
    <label for="name">Name</label>
    <input id="name" placeholder="Ada Lovelace" />
    <label for="email">Email</label>
    <input id="email" type="email" placeholder="you@example.com" />
    <label for="password">Password</label>
    <input id="password" type="password" placeholder="At least 8 characters" />
    <button id="registerButton">Create Account</button>
    <div id="error"></div>
  </div>
  <p class="switch">Already have an account? <a href="/login.html">Log In</a></p>
</div>
<script type="module" src="/src/register.js"></script>
</body>
</html>
```

```js
// frontend/src/register.js
import { setToken } from './auth.js';

const API_BASE = 'http://localhost:8000/api';
const errorEl = document.getElementById('error');
const button = document.getElementById('registerButton');

button.addEventListener('click', async () => {
  errorEl.style.display = 'none';
  button.disabled = true;

  try {
    const response = await fetch(`${API_BASE}/register`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({
        name: document.getElementById('name').value.trim(),
        email: document.getElementById('email').value.trim(),
        password: document.getElementById('password').value,
      }),
    });
    const body = await response.json();

    if (!response.ok || body.success === false) {
      const firstError = body.errors ? Object.values(body.errors)[0][0] : body.message;
      throw new Error(firstError || 'Registration failed');
    }

    setToken(body.token);
    window.location.href = '/backtest.html';
  } catch (err) {
    errorEl.textContent = err.message;
    errorEl.style.display = 'block';
  } finally {
    button.disabled = false;
  }
});
```

- [ ] **Step 4: Wire the new pages into the Vite build**

```js
// frontend/vite.config.js
import { defineConfig } from 'vite';
import { resolve } from 'path';

export default defineConfig({
  server: {
    port: 3000,
    open: true
  },
  build: {
    outDir: 'dist',
    assetsDir: 'assets',
    minify: 'esbuild',
    rollupOptions: {
      input: {
        main: resolve(__dirname, 'index.html'),
        backtest: resolve(__dirname, 'backtest.html'),
        login: resolve(__dirname, 'login.html'),
        register: resolve(__dirname, 'register.html'),
      },
    },
  },
});
```

- [ ] **Step 5: Manually verify**

Run the three dev servers (analytics, `php artisan serve`, `npm run dev` — see the parent slices' plans for exact commands). Open `http://localhost:3000/register.html`, create an account, confirm redirect to `backtest.html` and a token is present in `localStorage` (`chartsense_token`). Open `http://localhost:3000/login.html` in a fresh session, log in with the same credentials, confirm the same redirect and token behavior. Try a wrong password — confirm the inline error message renders.

- [ ] **Step 6: Commit**

```bash
cd /Users/sakhilebhayi/Dot/ChartSense
git add frontend/src/auth.js frontend/login.html frontend/register.html \
        frontend/src/login.js frontend/src/register.js frontend/vite.config.js
git commit -m "feat(frontend): add login and register pages"
```

---

### Task 7: Frontend — send bearer token from `backtest.js`, auth-state header

**Files:**
- Modify: `frontend/src/backtest.js`
- Modify: `frontend/backtest.html`
- Modify: `frontend/index.html`

**Interfaces:**
- Consumes: `auth.js` (Task 6).
- Produces: end-user-visible UI. No other task depends on this one.

- [ ] **Step 1: Send the token when present**

```js
// frontend/src/backtest.js — add the import at the top
import { getToken, clearToken, isLoggedIn } from './auth.js';
```

```js
// frontend/src/backtest.js — change the fetch call inside the click handler
  try {
    const headers = { 'Content-Type': 'application/json', Accept: 'application/json' };
    const token = getToken();
    if (token) {
      headers['Authorization'] = `Bearer ${token}`;
    }

    const response = await fetch(`${API_BASE}/backtests`, {
      method: 'POST',
      headers,
      body: JSON.stringify(payload),
    });
```

- [ ] **Step 2: Add an auth-state header fragment to both pages**

```html
<!-- frontend/backtest.html — add right after <a class="back-link" href="/">← Back</a> -->
  <span id="authState" style="float:right;font-size:14px;color:var(--muted)"></span>
```

```js
// frontend/src/backtest.js — add near the top, after the other const declarations
const authStateEl = document.getElementById('authState');
if (authStateEl) {
  if (isLoggedIn()) {
    authStateEl.innerHTML = '<a href="#" id="logoutLink" style="color:var(--accent)">Log out</a>';
    document.getElementById('logoutLink').addEventListener('click', (e) => {
      e.preventDefault();
      clearToken();
      window.location.reload();
    });
  } else {
    authStateEl.innerHTML = '<a href="/login.html" style="color:var(--accent)">Log in</a>';
  }
}
```

```html
<!-- frontend/index.html — add inside <nav>, alongside the existing backtest link -->
  <nav style="margin-top:14px">
    <a href="/backtest.html" style="color:var(--accent);text-decoration:none;font-size:15px">
      → Run a real backtest
    </a>
    <a href="/login.html" style="color:var(--accent);text-decoration:none;font-size:15px;margin-left:16px">
      Log in
    </a>
  </nav>
```

- [ ] **Step 3: Manually verify**

With the three dev servers running: while logged out, run a backtest — confirm it still succeeds (anonymous path unbroken) and the header shows "Log in". Log in via `login.html`, return to `backtest.html`, confirm the header now shows "Log out" and a backtest still succeeds — then check `backend/storage/logs` or query `backtest_runs` directly to confirm the row has a non-null `user_id` this time. Click "Log out", confirm the header reverts and a subsequent backtest still works (anonymous).

- [ ] **Step 4: Commit**

```bash
cd /Users/sakhilebhayi/Dot/ChartSense
git add frontend/src/backtest.js frontend/backtest.html frontend/index.html
git commit -m "feat(frontend): send bearer token when authenticated, add auth-state header"
```

---

## Plan Self-Review Notes

- **Spec coverage:** register/login/logout/me (Tasks 1–3), rate limiting with the anonymous/authenticated split (Task 4), `user_id` ownership proof (Task 5), and the frontend login/register/header UI (Tasks 6–7) all map directly to the design doc's scope. Out-of-scope items (email verification, password reset, forced login) are not touched by any task.
- **Consistency check:** `auth.js`'s exported function names (`getToken`, `setToken`, `clearToken`, `isLoggedIn`) are used identically in Task 6 (`login.js`, `register.js`) and Task 7 (`backtest.js`) — no drift. `AuthController`'s response shape (`{success, token, user: {id, name, email}}`) is consistent across register (Task 1) and login (Task 2), which is what lets `login.js`/`register.js` share the same handling pattern.
- **No placeholders:** every step has complete, real code.
