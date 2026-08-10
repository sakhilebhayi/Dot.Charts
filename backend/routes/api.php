<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BacktestController;
use App\Http\Controllers\ChartAnalysisController;
use App\Http\Controllers\CustomStrategyController;
use App\Http\Controllers\JournalEntryController;
use App\Http\Controllers\KnowledgePackController;
use App\Http\Controllers\OptionsVolController;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register'])
    ->middleware('throttle:auth-register');
Route::post('/login', [AuthController::class, 'login'])
    ->middleware('throttle:auth-login');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
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
Route::post('/backtests', [BacktestController::class, 'store'])
    ->middleware('throttle:backtests');
Route::get('/options/vol-signal/{symbol}', [OptionsVolController::class, 'show'])
    ->middleware('throttle:options-vol');
