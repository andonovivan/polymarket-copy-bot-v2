<?php

namespace App\Services;

use App\Models\TrackedWallet;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class LeaderboardDiscovery
{
    /**
     * Fetch the leaderboard from the Polymarket Data API.
     */
    public function fetchLeaderboard(?string $timePeriod = null, ?string $category = null, ?int $limit = null): array
    {
        $timePeriod ??= config('polymarket.discover_time_period', 'WEEK');
        $category ??= config('polymarket.discover_category', 'OVERALL');
        $limit ??= (int) config('polymarket.discover_limit', 20);

        try {
            $response = Http::timeout(15)
                ->get(config('polymarket.data_api_url') . '/v1/leaderboard', [
                    'timePeriod' => $timePeriod,
                    'orderBy' => 'PNL',
                    'category' => $category,
                    'limit' => $limit,
                ]);

            if (! $response->successful()) {
                Log::warning('leaderboard_fetch_failed', ['status' => $response->status()]);

                return [];
            }

            return $response->json() ?? [];
        } catch (\Throwable $e) {
            Log::warning('leaderboard_fetch_failed', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Fetch leaderboard and return filtered candidates (not yet tracked, meets thresholds).
     */
    public function discoverCandidates(?string $timePeriod = null, ?string $category = null, ?int $limit = null): array
    {
        $leaderboard = $this->fetchLeaderboard($timePeriod, $category, $limit);
        if (empty($leaderboard)) {
            return [];
        }

        $minPnl = (float) config('polymarket.discover_min_pnl', 500);
        $minVolume = (float) config('polymarket.discover_min_volume', 10000);
        $trackedAddresses = TrackedWallet::pluck('address')->map(fn ($a) => strtolower($a))->flip()->all();

        $candidates = [];
        foreach ($leaderboard as $entry) {
            $wallet = strtolower($entry['proxyWallet'] ?? '');
            if (! $wallet) {
                continue;
            }

            $pnl = (float) ($entry['pnl'] ?? 0);
            $vol = (float) ($entry['vol'] ?? 0);
            $alreadyTracked = isset($trackedAddresses[$wallet]);

            // Apply thresholds (but still include tracked wallets marked as such for the UI).
            if (! $alreadyTracked && ($pnl < $minPnl || $vol < $minVolume)) {
                continue;
            }

            $candidates[] = [
                'rank' => (int) ($entry['rank'] ?? 0),
                'wallet' => $wallet,
                'username' => $entry['userName'] ?? null,
                'pnl' => round($pnl, 2),
                'volume' => round($vol, 2),
                'profile_image' => $entry['profileImage'] ?? null,
                'already_tracked' => $alreadyTracked,
            ];
        }

        return $candidates;
    }

    /**
     * Add a candidate wallet to tracked wallets.
     * Returns the created TrackedWallet or null if already exists or invalid.
     */
    public function addCandidate(array $candidate): ?TrackedWallet
    {
        $address = strtolower(trim($candidate['wallet'] ?? ''));
        if (strlen($address) !== 42 || ! str_starts_with($address, '0x') || ! preg_match('/^0x[a-f0-9]{40}$/', $address)) {
            return null;
        }

        if (TrackedWallet::where('address', $address)->exists()) {
            return null;
        }

        $wallet = TrackedWallet::create([
            'address' => $address,
            'name' => $candidate['username'] ?? null,
            'profile_slug' => $candidate['username'] ?? null,
        ]);

        Log::info('wallet_discovered', [
            'address' => substr($address, 0, 10) . '...',
            'name' => $candidate['username'],
            'pnl' => $candidate['pnl'] ?? null,
        ]);

        return $wallet;
    }
}
