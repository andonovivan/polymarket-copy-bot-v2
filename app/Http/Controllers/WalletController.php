<?php

namespace App\Http\Controllers;

use App\Models\TrackedWallet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    /**
     * Add a tracked wallet.
     */
    public function store(Request $request): JsonResponse
    {
        $wallet = strtolower(trim($request->input('wallet', '')));

        if (! $wallet || strlen($wallet) !== 42 || ! str_starts_with($wallet, '0x')) {
            return response()->json(['error' => 'Invalid wallet address'], 400);
        }

        if (! preg_match('/^0x[a-f0-9]{40}$/', $wallet)) {
            return response()->json(['error' => 'Invalid wallet address — must be 0x + 40 hex chars'], 400);
        }

        if (TrackedWallet::where('address', $wallet)->exists()) {
            return response()->json(['error' => 'Wallet already tracked'], 400);
        }

        TrackedWallet::create(['address' => $wallet]);

        return response()->json([
            'ok' => true,
            'wallets' => TrackedWallet::count(),
        ]);
    }

    /**
     * Remove a tracked wallet.
     */
    public function destroy(Request $request): JsonResponse
    {
        $wallet = strtolower(trim($request->input('wallet', '')));

        $record = TrackedWallet::where('address', $wallet)->first();
        if (! $record) {
            return response()->json(['error' => 'Wallet not found'], 400);
        }

        $record->delete();

        return response()->json([
            'ok' => true,
            'wallets' => TrackedWallet::count(),
        ]);
    }
}
