<?php

use App\Http\Controllers\ActivityController;
use App\Http\Controllers\BalanceController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DiscoverController;
use App\Http\Controllers\GlobalPauseController;
use App\Http\Controllers\PositionController;
use App\Http\Controllers\TradeHistoryController;
use App\Http\Controllers\WalletController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\WalletReportController;
use Illuminate\Support\Facades\Route;

Route::get('/data', [DashboardController::class, 'data']);
Route::get('/positions', [PositionController::class, 'index']);
Route::get('/trades', [TradeHistoryController::class, 'index']);
Route::get('/activity', [ActivityController::class, 'index']);
Route::get('/wallets', [WalletController::class, 'index']);
Route::post('/wallets', [WalletController::class, 'store']);
Route::put('/wallets', [WalletController::class, 'update']);
Route::patch('/wallets/pause', [WalletController::class, 'togglePause']);
Route::delete('/wallets', [WalletController::class, 'destroy']);
Route::delete('/wallets/bulk', [WalletController::class, 'bulkDestroy']);
Route::patch('/wallets/bulk-pause', [WalletController::class, 'bulkTogglePause']);
Route::post('/close', [PositionController::class, 'close']);
Route::post('/close-all', [PositionController::class, 'closeAll']);
Route::post('/global-pause', [GlobalPauseController::class, 'toggle']);
Route::put('/balance', [BalanceController::class, 'update']);
Route::get('/wallet-report', [WalletReportController::class, 'index']);
Route::get('/wallet-report/summary', [WalletReportController::class, 'summary']);
Route::get('/discover', [DiscoverController::class, 'index']);
Route::post('/discover', [DiscoverController::class, 'store']);
Route::get('/settings', [SettingsController::class, 'index']);
Route::put('/settings', [SettingsController::class, 'update']);
Route::delete('/settings/{key}', [SettingsController::class, 'destroy']);
Route::post('/reset-data', [SettingsController::class, 'resetData']);
