<?php

namespace App\Http\Controllers;

use App\Models\TrackedWallet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    /**
     * List all tracked wallets.
     */
    public function index(): JsonResponse
    {
        $wallets = TrackedWallet::orderBy('id')->get()->map(fn ($w) => [
            'address' => $w->address,
            'name' => $w->name,
            'profile_slug' => $w->profile_slug,
            'is_paused' => (bool) $w->is_paused,
            'paused_at' => $w->paused_at?->timestamp,
            'pause_reason' => $w->pause_reason,
        ])->all();

        return response()->json(['data' => $wallets]);
    }

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

        $name = trim($request->input('name', ''));
        $profileSlug = trim($request->input('profile_slug', ''));

        TrackedWallet::create([
            'address' => $wallet,
            'name' => $name ?: null,
            'profile_slug' => $profileSlug ?: null,
        ]);

        return response()->json([
            'ok' => true,
            'wallets' => TrackedWallet::count(),
        ]);
    }

    /**
     * Update a tracked wallet's name and profile slug.
     */
    public function update(Request $request): JsonResponse
    {
        $wallet = strtolower(trim($request->input('wallet', '')));

        $record = TrackedWallet::where('address', $wallet)->first();
        if (! $record) {
            return response()->json(['error' => 'Wallet not found'], 400);
        }

        $name = trim($request->input('name', ''));
        $profileSlug = trim($request->input('profile_slug', ''));

        $record->update([
            'name' => $name ?: null,
            'profile_slug' => $profileSlug ?: null,
        ]);

        return response()->json(['ok' => true]);
    }

    /**
     * Toggle pause/resume for a tracked wallet.
     */
    public function togglePause(Request $request): JsonResponse
    {
        $wallet = strtolower(trim($request->input('wallet', '')));

        $record = TrackedWallet::where('address', $wallet)->first();
        if (! $record) {
            return response()->json(['error' => 'Wallet not found'], 400);
        }

        $paused = (bool) $request->input('paused', true);

        $record->update([
            'is_paused' => $paused,
            'paused_at' => $paused ? now() : null,
            'pause_reason' => $paused ? 'manual' : null,
        ]);

        return response()->json(['ok' => true, 'is_paused' => $paused]);
    }

    /**
     * Bulk-delete tracked wallets.
     */
    public function bulkDestroy(Request $request): JsonResponse
    {
        $wallets = $request->input('wallets', []);
        if (! is_array($wallets) || empty($wallets)) {
            return response()->json(['error' => 'No wallets provided'], 400);
        }

        $addresses = array_map(fn ($w) => strtolower(trim($w)), $wallets);
        $deleted = TrackedWallet::whereIn('address', $addresses)->delete();

        return response()->json([
            'ok' => true,
            'deleted' => $deleted,
            'wallets' => TrackedWallet::count(),
        ]);
    }

    /**
     * Bulk pause/resume tracked wallets.
     */
    public function bulkTogglePause(Request $request): JsonResponse
    {
        $wallets = $request->input('wallets', []);
        if (! is_array($wallets) || empty($wallets)) {
            return response()->json(['error' => 'No wallets provided'], 400);
        }

        $paused = (bool) $request->input('paused', true);
        $addresses = array_map(fn ($w) => strtolower(trim($w)), $wallets);

        $updated = TrackedWallet::whereIn('address', $addresses)->update([
            'is_paused' => $paused,
            'paused_at' => $paused ? now() : null,
            'pause_reason' => $paused ? 'manual' : null,
        ]);

        return response()->json([
            'ok' => true,
            'updated' => $updated,
            'paused' => $paused,
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
