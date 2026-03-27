<?php

namespace App\Http\Controllers;

use App\Models\TrackedWallet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ActivityController extends Controller
{
    /**
     * Unified activity feed: Buy/Sell/Redeem events from positions + trade_history.
     *
     * Each open position generates a Buy event.
     * Each closed trade generates a Buy event (at opened_at) and a Sell/Redeem event (at closed_at).
     * Redeem = market resolution (sell_price ~1.0 or ~0.0); Sell = manual/copy close.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->input('per_page', 10), 100);
        $page = max((int) $request->input('page', 1), 1);
        $sort = $request->input('sort', 'event_ts');
        $order = strtolower($request->input('order', 'desc')) === 'asc' ? 'asc' : 'desc';

        $sortable = ['event_ts', 'amount', 'type'];
        $orderBy = in_array($sort, $sortable) ? $sort : 'event_ts';

        $wallets = $request->input('wallets', []);
        $period = $request->input('period', 'ALL');
        $cutoff = match ($period) {
            '1D' => now()->subDay(),
            '1W' => now()->subWeek(),
            '1M' => now()->subMonth(),
            default => null,
        };

        // Sub-query 1: Buy events from open positions.
        $positionBuys = DB::table('positions')
            ->selectRaw("CONCAT('pb:', id) as row_id, 'Buy' as type, asset_id, market_slug, market_question, market_image, outcome, buy_price as price, shares, ROUND(buy_price * shares, 2) as amount, UNIX_TIMESTAMP(opened_at) as event_ts, copied_from_wallet")
            ->where('shares', '>', 0);

        // Sub-query 2: Buy events from closed trades.
        $tradeBuys = DB::table('trade_history')
            ->selectRaw("CONCAT('tb:', id) as row_id, 'Buy' as type, asset_id, market_slug, market_question, market_image, outcome, buy_price as price, shares, ROUND(buy_price * shares, 2) as amount, UNIX_TIMESTAMP(opened_at) as event_ts, copied_from_wallet");

        // Sub-query 3: Sell/Redeem events from closed trades.
        $tradeSells = DB::table('trade_history')
            ->selectRaw("CONCAT('ts:', id) as row_id, CASE WHEN sell_price >= 0.999 OR sell_price <= 0.001 THEN 'Redeem' ELSE 'Sell' END as type, asset_id, market_slug, market_question, market_image, outcome, sell_price as price, shares, ROUND(sell_price * shares, 2) as amount, UNIX_TIMESTAMP(closed_at) as event_ts, copied_from_wallet");

        // Apply wallet filter.
        if (! empty($wallets)) {
            $positionBuys->whereIn('copied_from_wallet', $wallets);
            $tradeBuys->whereIn('copied_from_wallet', $wallets);
            $tradeSells->whereIn('copied_from_wallet', $wallets);
        }

        // Apply time period filter.
        if ($cutoff) {
            $positionBuys->where('opened_at', '>=', $cutoff);
            $tradeBuys->where('opened_at', '>=', $cutoff);
            $tradeSells->where('closed_at', '>=', $cutoff);
        }

        $union = $positionBuys->unionAll($tradeBuys)->unionAll($tradeSells);
        $wrapped = DB::query()->fromSub($union, 'activity');

        $total = (clone $wrapped)->count();

        $items = $wrapped
            ->orderBy($orderBy, $order)
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        // Trader lookup.
        $walletLookup = TrackedWallet::all()->keyBy('address')->map(fn ($w) => [
            'name' => $w->name,
            'profile_slug' => $w->profile_slug,
        ])->all();

        $data = $items->map(function ($item) use ($walletLookup) {
            $wallet = $item->copied_from_wallet;
            $traderInfo = $wallet ? ($walletLookup[$wallet] ?? null) : null;

            return [
                'row_id' => $item->row_id,
                'type' => $item->type,
                'asset_id' => $item->asset_id,
                'market_slug' => $item->market_slug,
                'market_question' => $item->market_question,
                'market_image' => $item->market_image,
                'outcome' => $item->outcome,
                'price' => (float) $item->price,
                'shares' => (float) $item->shares,
                'amount' => (float) $item->amount,
                'event_ts' => (int) $item->event_ts,
                'trader_name' => $traderInfo['name'] ?? null,
                'trader_slug' => $traderInfo['profile_slug'] ?? null,
                'trader_wallet' => $wallet,
            ];
        })->all();

        return response()->json([
            'data' => $data,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'last_page' => max(1, (int) ceil($total / $perPage)),
        ]);
    }
}
