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
        $positions = [];
        $totalUnrealized = 0.0;
        $totalCost = 0.0;

        // Build a wallet lookup: address -> {name, profile_slug}
        $walletLookup = TrackedWallet::all()->keyBy('address')->map(fn ($w) => [
            'name' => $w->name,
            'profile_slug' => $w->profile_slug,
        ])->all();

        foreach (Position::where('shares', '>', 0)->get() as $pos) {
            $buyPrice = (float) $pos->buy_price;
            $shares = (float) $pos->shares;
            $currentPrice = $pos->current_price;
            $status = $pos->market_status ?? 'active';
            $cost = $buyPrice * $shares;

            $currentValue = $currentPrice !== null ? $currentPrice * $shares : null;
            $unrealized = $currentValue !== null ? $currentValue - $cost : null;

            $wallet = $pos->copied_from_wallet;
            $traderInfo = $wallet ? ($walletLookup[$wallet] ?? null) : null;

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
                'trader_name' => $traderInfo['name'] ?? null,
                'trader_slug' => $traderInfo['profile_slug'] ?? null,
                'trader_wallet' => $wallet,
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
            ->map(function ($t) use ($walletLookup) {
                $wallet = $t->copied_from_wallet;
                $traderInfo = $wallet ? ($walletLookup[$wallet] ?? null) : null;

                return [
                    'asset_id' => $t->asset_id,
                    'buy_price' => (float) $t->buy_price,
                    'sell_price' => (float) $t->sell_price,
                    'shares' => (float) $t->shares,
                    'pnl' => (float) $t->pnl,
                    'opened_at' => $t->opened_at?->timestamp ?? 0,
                    'closed_at' => $t->closed_at?->timestamp ?? 0,
                    'trader_name' => $traderInfo['name'] ?? null,
                    'trader_slug' => $traderInfo['profile_slug'] ?? null,
                    'trader_wallet' => $wallet,
                ];
            })
            ->values()
            ->all();

        // --- Wallet performance report ---
        $walletReport = [];

        // Initialize report entries for all tracked wallets
        foreach ($walletLookup as $addr => $info) {
            $walletReport[$addr] = [
                'address' => $addr,
                'name' => $info['name'],
                'profile_slug' => $info['profile_slug'],
                'realized_pnl' => 0.0,
                'unrealized_pnl' => 0.0,
                'total_trades' => 0,
                'winning_trades' => 0,
                'losing_trades' => 0,
                'open_positions' => 0,
                'total_invested' => 0.0,
            ];
        }

        // Aggregate realized stats from all trade history
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

        // Aggregate unrealized stats from open positions
        foreach ($positions as $p) {
            $w = $p['trader_wallet'];
            if (! $w || ! isset($walletReport[$w])) {
                continue;
            }
            $walletReport[$w]['open_positions']++;
            $walletReport[$w]['total_invested'] += $p['cost'];
            if ($p['unrealized_pnl'] !== null) {
                $walletReport[$w]['unrealized_pnl'] += $p['unrealized_pnl'];
            }
        }

        // Round and compute derived fields
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
            'wallet_report' => $walletReport,
            'polymarket_balance' => BotMeta::getValue('polymarket_balance'),
            'trading_balance' => BotMeta::getValue('trading_balance'),
            'dry_run' => config('polymarket.dry_run'),
            'tracked_wallets' => TrackedWallet::count(),
            'tracked_wallets_list' => TrackedWallet::orderBy('id')->get()->map(fn ($w) => [
                'address' => $w->address,
                'name' => $w->name,
                'profile_slug' => $w->profile_slug,
            ])->all(),
            'ts' => time(),
        ]);
    }
}
