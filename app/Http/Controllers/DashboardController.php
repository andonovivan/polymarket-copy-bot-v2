<?php

namespace App\Http\Controllers;

use App\Models\PnlSummary;
use App\Models\Position;
use App\Models\TradeHistory;
use App\Models\TrackedWallet;
use App\Services\PolymarketClient;
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
     */
    public function data(PolymarketClient $client)
    {
        $positions = [];
        $totalUnrealized = 0.0;
        $totalCost = 0.0;

        foreach (Position::where('shares', '>', 0)->get() as $pos) {
            $buyPrice = (float) $pos->buy_price;
            $shares = (float) $pos->shares;
            $currentPrice = $client->getMidpoint($pos->asset_id);
            $cost = $buyPrice * $shares;

            // If no midpoint, check if market is resolved to show accurate value.
            $status = 'active';
            if ($currentPrice === null) {
                $market = $client->getMarketByToken($pos->asset_id);
                if ($market !== null && $market['resolved']) {
                    $isWinner = $market['winner_token'] === $pos->asset_id;
                    $currentPrice = $isWinner ? 1.0 : 0.0;
                    $status = $isWinner ? 'resolved_won' : 'resolved_lost';
                }
            }

            $currentValue = $currentPrice !== null ? $currentPrice * $shares : null;
            $unrealized = $currentValue !== null ? $currentValue - $cost : null;

            $positions[] = [
                'asset_id' => $pos->asset_id,
                'shares' => round($shares, 4),
                'buy_price' => round($buyPrice, 4),
                'current_price' => $currentPrice !== null ? round($currentPrice, 4) : null,
                'cost' => round($cost, 4),
                'current_value' => $currentValue !== null ? round($currentValue, 4) : null,
                'unrealized_pnl' => $unrealized !== null ? round($unrealized, 4) : null,
                'opened_at' => $pos->opened_at?->timestamp ?? 0,
                'status' => $status,
            ];

            if ($unrealized !== null) {
                $totalUnrealized += $unrealized;
            }
            $totalCost += $cost;
        }

        $pnl = PnlSummary::singleton();
        $totalTrades = $pnl->total_trades;
        $winRate = $totalTrades > 0 ? round($pnl->winning_trades / $totalTrades * 100, 1) : 0;

        $recentTrades = TradeHistory::orderBy('id', 'desc')
            ->limit(500)
            ->get()
            ->map(fn ($t) => [
                'asset_id' => $t->asset_id,
                'buy_price' => (float) $t->buy_price,
                'sell_price' => (float) $t->sell_price,
                'shares' => (float) $t->shares,
                'pnl' => (float) $t->pnl,
                'opened_at' => $t->opened_at?->timestamp ?? 0,
                'closed_at' => $t->closed_at?->timestamp ?? 0,
            ])
            ->values()
            ->all();

        return response()->json([
            'positions' => $positions,
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
            'recent_trades' => $recentTrades,
            'dry_run' => config('polymarket.dry_run'),
            'tracked_wallets' => TrackedWallet::count(),
            'tracked_wallets_list' => TrackedWallet::orderBy('id')->pluck('address')->all(),
            'ts' => time(),
        ]);
    }
}
