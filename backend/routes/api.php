<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BacktestController;
use App\Http\Controllers\ChartAnalysisController;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::post('/chart/analyze', [ChartAnalysisController::class, 'analyzeChart']);
Route::post('/backtests', [BacktestController::class, 'store']);
