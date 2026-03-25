<?php

namespace App\Http\Controllers;

use App\Models\BotMeta;
use App\Models\PnlSummary;
use App\Models\Position;
use App\Models\TrackedWallet;
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
     */
    public function data()
    {
        $totalUnrealized = 0.0;
        $totalCost = 0.0;
        $openPositionCount = 0;

        // Compute summary stats from all open positions.
        foreach (Position::where('shares', '>', 0)->get() as $pos) {
            $cost = (float) $pos->buy_price * (float) $pos->shares;
            $currentValue = $pos->current_price !== null ? (float) $pos->current_price * (float) $pos->shares : null;
            $unrealized = $currentValue !== null ? $currentValue - $cost : null;

            if ($unrealized !== null) {
                $totalUnrealized += $unrealized;
            }
            $totalCost += $cost;
            $openPositionCount++;
        }

        $pnl = PnlSummary::singleton();
        $totalTrades = $pnl->total_trades;
        $winRate = $totalTrades > 0 ? round($pnl->winning_trades / $totalTrades * 100, 1) : 0;

        return response()->json([
            'open_positions_count' => $openPositionCount,
            'total_unrealized' => round($totalUnrealized, 4),
            'total_cost' => round($totalCost, 4),
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
