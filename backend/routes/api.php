<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BacktestController;
use App\Http\Controllers\ChartAnalysisController;
use App\Http\Controllers\CustomStrategyController;
use App\Http\Controllers\EcosystemSwitcherController;
use App\Http\Controllers\JournalEntryController;
use App\Http\Controllers\KnowledgePackController;
use App\Http\Controllers\OptionsVolController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\SsoSessionController;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register'])
    ->middleware(['charts.local-auth', 'throttle:auth-register']);
Route::post('/login', [AuthController::class, 'login'])
    ->middleware(['charts.local-auth', 'throttle:auth-login']);

// R-54: tells the sign-in pages which mode the platform is in (public, no secrets).
Route::get('/auth/config', [SsoSessionController::class, 'config']);

// Ends the session and returns the InfoDot end-session address. Cookie session + CSRF only (never a bearer call).
Route::post('/auth/sso-logout', [SsoSessionController::class, 'logout'])
    ->middleware('charts.stateful');

// charts.stateful: with DOTSSO_ENABLED, a same-origin SPA request is authenticated by the Laravel session cookie (CSRF-checked);
// a request with a bearer token, or with the flag off, is authenticated by the token exactly as before.
Route::middleware(['charts.stateful', \Dot\SsoClient\Http\Middleware\EnforceSsoRevocation::class, 'auth:sanctum'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    // Header search over the account's own backtests, journal entries and strategies (account-scoped, limited).
    Route::get('/search', SearchController::class)->middleware('throttle:search');
    Route::get('/backtests', [BacktestController::class, 'index']);
    Route::get('/backtests/{id}', [BacktestController::class, 'show']);
    Route::delete('/backtests/{id}', [BacktestController::class, 'destroy']);
    Route::post('/strategies', [CustomStrategyController::class, 'store']);
    Route::get('/strategies', [CustomStrategyController::class, 'index']);
    Route::get('/strategies/{id}', [CustomStrategyController::class, 'show']);
    Route::delete('/strategies/{id}', [CustomStrategyController::class, 'destroy']);

    Route::post('/journal-entries', [JournalEntryController::class, 'store']);
    Route::get('/journal-entries', [JournalEntryController::class, 'index']);
    Route::get('/journal-entries/{id}', [JournalEntryController::class, 'show']);
    Route::patch('/journal-entries/{id}', [JournalEntryController::class, 'update']);
    Route::delete('/journal-entries/{id}', [JournalEntryController::class, 'destroy']);

    Route::middleware('operator')->group(function () {
        Route::post('/knowledge-packs/generate', [KnowledgePackController::class, 'generate']);
        Route::get('/knowledge-packs', [KnowledgePackController::class, 'index']);
        Route::get('/knowledge-packs/pending', [KnowledgePackController::class, 'pending']);
        Route::get('/knowledge-packs/{id}', [KnowledgePackController::class, 'show']);
        Route::post('/knowledge-packs/ingest-check', [KnowledgePackController::class, 'ingestCheck']);
        Route::post('/knowledge-packs/{id}/approve', [KnowledgePackController::class, 'approve']);
        Route::post('/knowledge-packs/{id}/reject', [KnowledgePackController::class, 'reject']);
    });
});

Route::post('/chart/analyze', [ChartAnalysisController::class, 'analyzeChart'])
    ->middleware('throttle:chart-analysis');
// Public, but attaches the signed-in person to the run: a session cookie (when there is one) is honoured, see StatefulWhenSso.
Route::post('/backtests', [BacktestController::class, 'store'])
    ->middleware(['charts.stateful:optional', 'throttle:backtests']);
Route::get('/options/vol-signal/{symbol}', [OptionsVolController::class, 'show'])
    ->middleware('throttle:options-vol');

// The registry for the shared platform switcher (public, not personal: see the controller).
Route::get('/ecosystem/switcher', EcosystemSwitcherController::class);
