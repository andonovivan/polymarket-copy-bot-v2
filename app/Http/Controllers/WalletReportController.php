<?php

namespace App\Http\Controllers;

use App\Models\Position;
use App\Models\TradeHistory;
use App\Models\TrackedWallet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WalletReportController extends Controller
{
    /**
     * Paginated wallet performance report with server-side sorting.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->input('per_page', 10), 100);
        $page = max((int) $request->input('page', 1), 1);
        $sort = $request->input('sort', 'combined_pnl');
        $order = strtolower($request->input('order', 'desc')) === 'asc' ? 'asc' : 'desc';

        // Build full report in memory (wallet count is small — typically < 50).
        $walletLookup = TrackedWallet::all()->keyBy('address');

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

        // Aggregate realized stats.
        foreach (TradeHistory::all() as $t) {
            $w = $t->copied_from_wallet;
            if (! isset($report[$w])) {
                continue;
            }
            $report[$w]['realized_pnl'] += (float) $t->pnl;
            $report[$w]['total_trades']++;
            if ((float) $t->pnl >= 0) {
                $report[$w]['winning_trades']++;
            } else {
                $report[$w]['losing_trades']++;
            }
        }

        // Aggregate unrealized stats from open positions.
        foreach (Position::where('shares', '>', 0)->get() as $pos) {
            $w = $pos->copied_from_wallet;
            if (! $w || ! isset($report[$w])) {
                continue;
            }
            $cost = (float) $pos->buy_price * (float) $pos->shares;
            $report[$w]['open_positions']++;
            $report[$w]['total_invested'] += $cost;
            $value = $pos->current_price !== null ? (float) $pos->current_price * (float) $pos->shares : null;
            if ($value !== null) {
                $report[$w]['unrealized_pnl'] += $value - $cost;
            }
        }

        // Compute derived fields.
        $rows = array_values(array_map(function ($r) {
            $r['realized_pnl'] = round($r['realized_pnl'], 4);
            $r['unrealized_pnl'] = round($r['unrealized_pnl'], 4);
            $r['combined_pnl'] = round($r['realized_pnl'] + $r['unrealized_pnl'], 4);
            $r['total_invested'] = round($r['total_invested'], 4);
            $r['win_rate'] = $r['total_trades'] > 0
                ? round($r['winning_trades'] / $r['total_trades'] * 100, 1)
                : 0;

            return $r;
        }, $report));

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
