<?php

namespace App\Http\Controllers;

use App\Models\BotMeta;
use App\Services\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BalanceController extends Controller
{
    /**
     * Update the trading balance limit.
     */
    public function update(Request $request): JsonResponse
    {
        $tradingBalance = $request->input('trading_balance');

        if ($tradingBalance === null || $tradingBalance === '') {
            // Clear the limit (no cap).
            BotMeta::setValue('trading_balance', '');

            return response()->json(['ok' => true, 'trading_balance' => null]);
        }

        $tradingBalance = (float) $tradingBalance;
        if ($tradingBalance < 0) {
            return response()->json(['error' => 'Trading balance cannot be negative'], 400);
        }

        // When not in dry-run, trading balance cannot exceed the real Polymarket balance.
        if (! Setting::get('dry_run', true)) {
            $realBalance = BotMeta::getValue('polymarket_balance');
            if ($realBalance !== null && $realBalance !== '') {
                $realBalance = (float) $realBalance;
                if ($tradingBalance > $realBalance) {
                    return response()->json([
                        'error' => 'Trading balance cannot exceed your Polymarket balance ($' . number_format($realBalance, 2) . ')',
                    ], 400);
                }
            }
        }

        BotMeta::setValue('trading_balance', (string) round($tradingBalance, 2));

        return response()->json(['ok' => true, 'trading_balance' => round($tradingBalance, 2)]);
    }
}
