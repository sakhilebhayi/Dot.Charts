<?php

use App\Http\Controllers\BacktestController;
use App\Http\Controllers\ChartAnalysisController;
use Illuminate\Support\Facades\Route;

Route::post('/chart/analyze', [ChartAnalysisController::class, 'analyzeChart']);
Route::post('/backtests', [BacktestController::class, 'store']);
