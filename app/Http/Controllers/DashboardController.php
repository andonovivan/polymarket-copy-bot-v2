<?php

namespace App\Http\Controllers;

use App\Models\BotMeta;
use App\Models\PnlSummary;
use App\Models\TrackedWallet;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class DashboardController extends Controller
{
    /**
     * Serve the dashboard page via Inertia.
     */
    public function index()
    {
        return Inertia::render('Dashboard');
    }

    /**
     * Return full dashboard JSON data.
     *
     * Reads all prices from the DB (populated by bot:update-prices).
     * Zero external API calls — response is instant.
     * Uses DB aggregates instead of loading all rows into PHP.
     */
    public function data()
    {
        // Single query to compute all position stats.
        $posStats = DB::table('positions')
            ->where('shares', '>', 0)
            ->selectRaw('COUNT(*) as count')
            ->selectRaw('COALESCE(SUM(buy_price * shares), 0) as total_cost')
            ->selectRaw('COALESCE(SUM(CASE WHEN current_price IS NOT NULL THEN (current_price - buy_price) * shares ELSE 0 END), 0) as total_unrealized')
            ->first();

        $openPositionCount = (int) $posStats->count;
        $totalCost = round((float) $posStats->total_cost, 4);
        $totalUnrealized = round((float) $posStats->total_unrealized, 4);

        $pnl = PnlSummary::singleton();
        $totalTrades = $pnl->total_trades;
        $winRate = $totalTrades > 0 ? round($pnl->winning_trades / $totalTrades * 100, 1) : 0;

        return response()->json([
            'open_positions_count' => $openPositionCount,
            'total_unrealized' => $totalUnrealized,
            'total_cost' => $totalCost,
            'realized' => [
                'total' => round((float) $pnl->total_realized, 4),
                'trades' => $totalTrades,
                'winning' => $pnl->winning_trades,
                'losing' => $pnl->losing_trades,
                'win_rate' => $winRate,
            ],
            'combined_pnl' => round((float) $pnl->total_realized + $totalUnrealized, 4),
            'polymarket_balance' => BotMeta::getValue('polymarket_balance'),
            'trading_balance' => BotMeta::getValue('trading_balance'),
            'dry_run' => config('polymarket.dry_run'),
            'tracked_wallets' => TrackedWallet::count(),
            'tracked_wallets_list' => TrackedWallet::orderBy('id')->get()->map(fn ($w) => [
                'address' => $w->address,
                'name' => $w->name,
                'profile_slug' => $w->profile_slug,
                'is_paused' => (bool) $w->is_paused,
                'paused_at' => $w->paused_at?->timestamp,
                'pause_reason' => $w->pause_reason,
            ])->all(),
            'ts' => time(),
        ]);
    }
}
