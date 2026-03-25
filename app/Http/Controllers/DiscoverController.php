<?php

namespace App\Http\Controllers;

use App\Models\TrackedWallet;
use App\Services\LeaderboardDiscovery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DiscoverController extends Controller
{
    /**
     * Fetch leaderboard candidates without adding any.
     */
    public function index(Request $request, LeaderboardDiscovery $discovery): JsonResponse
    {
        $timePeriod = $request->input('time_period');
        $category = $request->input('category');

        $candidates = $discovery->discoverCandidates($timePeriod, $category);

        return response()->json(['candidates' => $candidates]);
    }

    /**
     * Add selected wallets from the leaderboard.
     * Accepts candidates array with wallet + username metadata from the frontend.
     */
    public function store(Request $request, LeaderboardDiscovery $discovery): JsonResponse
    {
        $candidates = $request->input('candidates', []);
        if (empty($candidates)) {
            return response()->json(['error' => 'No candidates specified'], 400);
        }

        $added = 0;
        foreach ($candidates as $candidate) {
            if ($discovery->addCandidate($candidate)) {
                $added++;
            }
        }

        return response()->json([
            'ok' => true,
            'added' => $added,
            'wallets' => TrackedWallet::count(),
        ]);
    }
}
