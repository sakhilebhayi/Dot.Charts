<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ChartAnalysisController;

Route::post('/chart/analyze', [ChartAnalysisController::class, 'analyzeChart']);
