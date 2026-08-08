<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BacktestController;
use App\Http\Controllers\ChartAnalysisController;
use App\Http\Controllers\CustomStrategyController;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

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
});

Route::post('/chart/analyze', [ChartAnalysisController::class, 'analyzeChart'])
    ->middleware('throttle:chart-analysis');
Route::post('/backtests', [BacktestController::class, 'store'])
    ->middleware('throttle:backtests');
