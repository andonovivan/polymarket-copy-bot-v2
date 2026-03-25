<?php

use App\Http\Controllers\BalanceController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PositionController;
use App\Http\Controllers\TradeHistoryController;
use App\Http\Controllers\WalletController;
use Illuminate\Support\Facades\Route;

Route::get('/data', [DashboardController::class, 'data']);
Route::get('/positions', [PositionController::class, 'index']);
Route::get('/trades', [TradeHistoryController::class, 'index']);
Route::post('/wallets', [WalletController::class, 'store']);
Route::put('/wallets', [WalletController::class, 'update']);
Route::patch('/wallets/pause', [WalletController::class, 'togglePause']);
Route::delete('/wallets', [WalletController::class, 'destroy']);
Route::post('/close', [PositionController::class, 'close']);
Route::put('/balance', [BalanceController::class, 'update']);
