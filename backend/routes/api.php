<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BacktestController;
use App\Http\Controllers\ChartAnalysisController;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::get('/backtests', [BacktestController::class, 'index']);
    Route::get('/backtests/{id}', [BacktestController::class, 'show']);
});

Route::post('/chart/analyze', [ChartAnalysisController::class, 'analyzeChart'])
    ->middleware('throttle:chart-analysis');
Route::post('/backtests', [BacktestController::class, 'store'])
    ->middleware('throttle:backtests');
