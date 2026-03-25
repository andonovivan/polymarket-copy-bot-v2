<?php

namespace App\Http\Controllers;

use App\Models\BotMeta;
use App\Models\PnlSummary;
use App\Models\Position;
use App\Models\TradeHistory;
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

        // Build a wallet lookup: address -> {name, profile_slug, pause info}
        $walletLookup = TrackedWallet::all()->keyBy('address')->map(fn ($w) => [
            'name' => $w->name,
            'profile_slug' => $w->profile_slug,
            'is_paused' => (bool) $w->is_paused,
            'paused_at' => $w->paused_at?->timestamp,
            'pause_reason' => $w->pause_reason,
        ])->all();

        // Compute summary stats from all open positions (lightweight — no serialization).
        $positionRows = [];
        foreach (Position::where('shares', '>', 0)->get() as $pos) {
            $buyPrice = (float) $pos->buy_price;
            $shares = (float) $pos->shares;
            $currentPrice = $pos->current_price;
            $cost = $buyPrice * $shares;

            $currentValue = $currentPrice !== null ? $currentPrice * $shares : null;
            $unrealized = $currentValue !== null ? $currentValue - $cost : null;

            if ($unrealized !== null) {
                $totalUnrealized += $unrealized;
            }
            $totalCost += $cost;
            $openPositionCount++;

            // Keep for wallet report aggregation.
            $positionRows[] = [
                'wallet' => $pos->copied_from_wallet,
                'cost' => $cost,
                'unrealized_pnl' => $unrealized,
            ];
        }

        $pnl = PnlSummary::singleton();
        $totalTrades = $pnl->total_trades;
        $winRate = $totalTrades > 0 ? round($pnl->winning_trades / $totalTrades * 100, 1) : 0;

        // --- Wallet performance report ---
        $walletReport = [];

        // Initialize report entries for all tracked wallets.
        foreach ($walletLookup as $addr => $info) {
            $walletReport[$addr] = [
                'address' => $addr,
                'name' => $info['name'],
                'profile_slug' => $info['profile_slug'],
                'is_paused' => $info['is_paused'],
                'paused_at' => $info['paused_at'],
                'pause_reason' => $info['pause_reason'],
                'realized_pnl' => 0.0,
                'unrealized_pnl' => 0.0,
                'total_trades' => 0,
                'winning_trades' => 0,
                'losing_trades' => 0,
                'open_positions' => 0,
                'total_invested' => 0.0,
            ];
        }

        // Aggregate realized stats from all trade history.
        foreach (TradeHistory::all() as $t) {
            $w = $t->copied_from_wallet;
            if (! isset($walletReport[$w])) {
                continue;
            }
            $walletReport[$w]['realized_pnl'] += (float) $t->pnl;
            $walletReport[$w]['total_trades']++;
            if ((float) $t->pnl >= 0) {
                $walletReport[$w]['winning_trades']++;
            } else {
                $walletReport[$w]['losing_trades']++;
            }
        }

        // Aggregate unrealized stats from open positions.
        foreach ($positionRows as $p) {
            $w = $p['wallet'];
            if (! $w || ! isset($walletReport[$w])) {
                continue;
            }
            $walletReport[$w]['open_positions']++;
            $walletReport[$w]['total_invested'] += $p['cost'];
            if ($p['unrealized_pnl'] !== null) {
                $walletReport[$w]['unrealized_pnl'] += $p['unrealized_pnl'];
            }
        }

        // Round and compute derived fields.
        $walletReport = array_values(array_map(function ($r) {
            $r['realized_pnl'] = round($r['realized_pnl'], 4);
            $r['unrealized_pnl'] = round($r['unrealized_pnl'], 4);
            $r['combined_pnl'] = round($r['realized_pnl'] + $r['unrealized_pnl'], 4);
            $r['total_invested'] = round($r['total_invested'], 4);
            $r['win_rate'] = $r['total_trades'] > 0
                ? round($r['winning_trades'] / $r['total_trades'] * 100, 1)
                : 0;

            return $r;
        }, $walletReport));

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
            'wallet_report' => $walletReport,
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
