<?php

use App\Http\Controllers\MarketScannerController;
use Illuminate\Support\Facades\Route;

Route::get('/', [MarketScannerController::class, 'index'])->name('analysis.index');
Route::post('/analyze', [MarketScannerController::class, 'run'])->name('analysis.run');
Route::get('/results', [MarketScannerController::class, 'results'])->name('analysis.results');