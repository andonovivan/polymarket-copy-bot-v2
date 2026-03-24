<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PositionController;
use App\Http\Controllers\WalletController;
use Illuminate\Support\Facades\Route;

Route::get('/data', [DashboardController::class, 'data']);
Route::post('/wallets', [WalletController::class, 'store']);
Route::delete('/wallets', [WalletController::class, 'destroy']);
Route::post('/close', [PositionController::class, 'close']);
