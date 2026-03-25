<?php

namespace App\Http\Controllers;

use App\Models\TrackedWallet;
use App\Services\WalletScoring;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WalletReportController extends Controller
{
    /**
     * Summary stats across all wallets (separate from paginated data).
     */
    public function summary(): JsonResponse
    {
        $wallets = TrackedWallet::all();
        $addresses = $wallets->pluck('address')->all();

        // Realized P&L per wallet.
        $realizedByWallet = DB::table('trade_history')
            ->selectRaw('copied_from_wallet, SUM(pnl) as realized_pnl')
            ->whereIn('copied_from_wallet', $addresses)
            ->groupBy('copied_from_wallet')
            ->pluck('realized_pnl', 'copied_from_wallet');

        // Unrealized P&L per wallet.
        $unrealizedByWallet = DB::table('positions')
            ->selectRaw('copied_from_wallet, SUM(CASE WHEN current_price IS NOT NULL THEN (current_price - buy_price) * shares ELSE 0 END) as unrealized_pnl')
            ->where('shares', '>', 0)
            ->whereIn('copied_from_wallet', $addresses)
            ->groupBy('copied_from_wallet')
            ->pluck('unrealized_pnl', 'copied_from_wallet');

        $profitable = 0;
        $losing = 0;
        $paused = 0;
        $bestName = null;
        $bestPnl = null;

        foreach ($wallets as $w) {
            $combined = (float) ($realizedByWallet[$w->address] ?? 0) + (float) ($unrealizedByWallet[$w->address] ?? 0);
            if ($combined > 0) {
                $profitable++;
            } elseif ($combined < 0) {
                $losing++;
            }
            if ($w->is_paused) {
                $paused++;
            }
            if ($bestPnl === null || $combined > $bestPnl) {
                $bestPnl = $combined;
                $bestName = $w->name ?: substr($w->address, 0, 8) . '...';
            }
        }

        // Compute average composite score.
        $walletScores = (new WalletScoring)->compute($addresses);
        $scores = array_filter(array_map(fn ($s) => $s['composite_score'] ?? null, $walletScores), fn ($v) => $v !== null);
        $avgScore = count($scores) > 0 ? (int) round(array_sum($scores) / count($scores)) : null;

        return response()->json([
            'total' => $wallets->count(),
            'profitable' => $profitable,
            'losing' => $losing,
            'paused' => $paused,
            'best_performer' => $bestName,
            'average_score' => $avgScore,
        ]);
    }

    /**
     * Paginated wallet performance report with server-side sorting.
     *
     * Uses SQL aggregation instead of loading all trades/positions into PHP.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->input('per_page', 10), 100);
        $page = max((int) $request->input('page', 1), 1);
        $sort = $request->input('sort', 'combined_pnl');
        $order = strtolower($request->input('order', 'desc')) === 'asc' ? 'asc' : 'desc';

        // Build full report — wallet count is small (typically < 100).
        $walletLookup = TrackedWallet::all()->keyBy('address');
        $addresses = $walletLookup->keys()->all();

        $report = [];
        foreach ($walletLookup as $addr => $w) {
            $report[$addr] = [
                'address' => $addr,
                'name' => $w->name,
                'profile_slug' => $w->profile_slug,
                'is_paused' => (bool) $w->is_paused,
                'paused_at' => $w->paused_at?->timestamp,
                'pause_reason' => $w->pause_reason,
                'realized_pnl' => 0.0,
                'unrealized_pnl' => 0.0,
                'total_trades' => 0,
                'winning_trades' => 0,
                'losing_trades' => 0,
                'open_positions' => 0,
                'total_invested' => 0.0,
            ];
        }

        // Batch-aggregate realized stats with a single query.
        $realizedStats = DB::table('trade_history')
            ->select('copied_from_wallet')
            ->selectRaw('COUNT(*) as total_trades')
            ->selectRaw('SUM(CASE WHEN pnl >= 0 THEN 1 ELSE 0 END) as winning_trades')
            ->selectRaw('SUM(CASE WHEN pnl < 0 THEN 1 ELSE 0 END) as losing_trades')
            ->selectRaw('SUM(pnl) as realized_pnl')
            ->whereIn('copied_from_wallet', $addresses)
            ->groupBy('copied_from_wallet')
            ->get();

        foreach ($realizedStats as $rs) {
            $w = $rs->copied_from_wallet;
            if (! isset($report[$w])) {
                continue;
            }
            $report[$w]['total_trades'] = (int) $rs->total_trades;
            $report[$w]['winning_trades'] = (int) $rs->winning_trades;
            $report[$w]['losing_trades'] = (int) $rs->losing_trades;
            $report[$w]['realized_pnl'] = (float) $rs->realized_pnl;
        }

        // Batch-aggregate unrealized stats with a single query.
        $positionStats = DB::table('positions')
            ->select('copied_from_wallet')
            ->selectRaw('COUNT(*) as open_positions')
            ->selectRaw('SUM(buy_price * shares) as total_invested')
            ->selectRaw('SUM(CASE WHEN current_price IS NOT NULL THEN (current_price - buy_price) * shares ELSE 0 END) as unrealized_pnl')
            ->where('shares', '>', 0)
            ->whereIn('copied_from_wallet', $addresses)
            ->groupBy('copied_from_wallet')
            ->get();

        foreach ($positionStats as $ps) {
            $w = $ps->copied_from_wallet;
            if (! isset($report[$w])) {
                continue;
            }
            $report[$w]['open_positions'] = (int) $ps->open_positions;
            $report[$w]['total_invested'] = (float) $ps->total_invested;
            $report[$w]['unrealized_pnl'] = (float) $ps->unrealized_pnl;
        }

        // Compute advanced metrics via WalletScoring service.
        $walletScores = (new WalletScoring)->compute($addresses);

        // Compute derived fields and merge scores.
        $rows = array_values(array_map(function ($r) use ($walletScores) {
            $r['realized_pnl'] = round($r['realized_pnl'], 4);
            $r['unrealized_pnl'] = round($r['unrealized_pnl'], 4);
            $r['combined_pnl'] = round($r['realized_pnl'] + $r['unrealized_pnl'], 4);
            $r['total_invested'] = round($r['total_invested'], 4);
            $r['win_rate'] = $r['total_trades'] > 0
                ? round($r['winning_trades'] / $r['total_trades'] * 100, 1)
                : 0;

            $scores = $walletScores[$r['address']] ?? null;
            $r['composite_score'] = $scores['composite_score'] ?? null;
            $r['profit_factor'] = $scores['profit_factor'] ?? null;
            $r['rolling_expectancy'] = $scores['rolling_expectancy'] ?? null;
            $r['max_drawdown_pct'] = $scores['max_drawdown_pct'] ?? null;
            $r['consistency'] = $scores['consistency'] ?? null;
            $r['score_breakdown'] = $scores['score_breakdown'] ?? null;

            return $r;
        }, $report));

        // Filter out wallets with no activity (no trades and no open positions).
        $rows = array_values(array_filter($rows, fn ($r) => $r['total_trades'] > 0 || $r['open_positions'] > 0));

        // Sort.
        $sortKey = $sort;
        usort($rows, function ($a, $b) use ($sortKey, $order) {
            $va = $a[$sortKey] ?? 0;
            $vb = $b[$sortKey] ?? 0;
            if (is_string($va)) {
                $cmp = strcasecmp($va, $vb);
            } else {
                $cmp = $va <=> $vb;
            }

            return $order === 'asc' ? $cmp : -$cmp;
        });

        // Paginate.
        $total = count($rows);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $offset = ($page - 1) * $perPage;
        $data = array_slice($rows, $offset, $perPage);

        return response()->json([
            'data' => $data,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'last_page' => $lastPage,
        ]);
    }
}
