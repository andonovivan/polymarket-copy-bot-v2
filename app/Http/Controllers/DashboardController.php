<?php

namespace App\Http\Controllers;

use App\Models\BotMeta;
use App\Models\PnlSummary;
use App\Models\TrackedWallet;
use Illuminate\Http\Request;
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
     *
     * Supports optional filters:
     * - wallets[]: array of wallet addresses to filter by
     * - period: 1D, 1W, 1M, ALL (time filter for realized trades)
     */
    public function data(Request $request)
    {
        $wallets = $request->input('wallets', []);
        $period = $request->input('period', 'ALL');
        $hasFilters = !empty($wallets) || $period !== 'ALL';

        // Position stats (unrealized) — filtered by wallet only (current state).
        $posQuery = DB::table('positions')->where('shares', '>', 0);
        if (!empty($wallets)) {
            $posQuery->whereIn('copied_from_wallet', $wallets);
        }

        $posStats = $posQuery
            ->selectRaw('COUNT(*) as count')
            ->selectRaw('COALESCE(SUM(buy_price * shares), 0) as total_cost')
            ->selectRaw('COALESCE(SUM(CASE WHEN current_price IS NOT NULL THEN (current_price - buy_price) * shares ELSE 0 END), 0) as total_unrealized')
            ->first();

        $openPositionCount = (int) $posStats->count;
        $totalCost = round((float) $posStats->total_cost, 4);
        $totalUnrealized = round((float) $posStats->total_unrealized, 4);

        // Realized stats — use PnlSummary singleton when unfiltered, raw query otherwise.
        if ($hasFilters) {
            $thQuery = DB::table('trade_history');
            if (!empty($wallets)) {
                $thQuery->whereIn('copied_from_wallet', $wallets);
            }
            $cutoff = match ($period) {
                '1D' => now()->subDay(),
                '1W' => now()->subWeek(),
                '1M' => now()->subMonth(),
                default => null,
            };
            if ($cutoff) {
                $thQuery->where('closed_at', '>=', $cutoff);
            }

            $realizedStats = $thQuery
                ->selectRaw('COALESCE(SUM(pnl), 0) as total_realized')
                ->selectRaw('COUNT(*) as total_trades')
                ->selectRaw('COALESCE(SUM(CASE WHEN pnl >= 0 THEN 1 ELSE 0 END), 0) as winning_trades')
                ->selectRaw('COALESCE(SUM(CASE WHEN pnl < 0 THEN 1 ELSE 0 END), 0) as losing_trades')
                ->first();

            $totalRealized = round((float) $realizedStats->total_realized, 4);
            $totalTrades = (int) $realizedStats->total_trades;
            $winningTrades = (int) $realizedStats->winning_trades;
            $losingTrades = (int) $realizedStats->losing_trades;
        } else {
            $pnl = PnlSummary::singleton();
            $totalRealized = round((float) $pnl->total_realized, 4);
            $totalTrades = $pnl->total_trades;
            $winningTrades = $pnl->winning_trades;
            $losingTrades = $pnl->losing_trades;
        }

        $winRate = $totalTrades > 0 ? round($winningTrades / $totalTrades * 100, 1) : 0;

        return response()->json([
            'open_positions_count' => $openPositionCount,
            'total_unrealized' => $totalUnrealized,
            'total_cost' => $totalCost,
            'realized' => [
                'total' => $totalRealized,
                'trades' => $totalTrades,
                'winning' => $winningTrades,
                'losing' => $losingTrades,
                'win_rate' => $winRate,
            ],
            'combined_pnl' => round($totalRealized + $totalUnrealized, 4),
            'polymarket_balance' => BotMeta::getValue('polymarket_balance'),
            'trading_balance' => BotMeta::getValue('trading_balance'),
            'dry_run' => config('polymarket.dry_run'),
            'global_paused' => BotMeta::getValue('global_paused') === '1',
            'tracked_wallets' => TrackedWallet::count(),
            'ts' => time(),
        ]);
    }
}
