<?php

namespace App\Http\Controllers;

use App\Models\TradeHistory;
use App\Models\TrackedWallet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TradeHistoryController extends Controller
{
    /**
     * Paginated list of closed trades.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->input('per_page', 10), 100);
        $sort = $request->input('sort', 'closed_at');
        $order = strtolower($request->input('order', 'desc')) === 'asc' ? 'asc' : 'desc';

        // Whitelist sortable columns.
        $sortable = [
            'trader_name' => 'copied_from_wallet',
            'asset_id' => 'asset_id',
            'buy_price' => 'buy_price',
            'sell_price' => 'sell_price',
            'shares' => 'shares',
            'pnl' => 'pnl',
            'opened_at' => 'opened_at',
            'closed_at' => 'closed_at',
        ];

        $orderBy = $sortable[$sort] ?? 'closed_at';

        $walletLookup = TrackedWallet::all()->keyBy('address')->map(fn ($w) => [
            'name' => $w->name,
            'profile_slug' => $w->profile_slug,
        ])->all();

        $query = TradeHistory::query();

        // Optional wallet filter.
        $wallets = $request->input('wallets', []);
        if (!empty($wallets)) {
            $query->whereIn('copied_from_wallet', $wallets);
        }

        // Optional time period filter.
        $period = $request->input('period', 'ALL');
        $cutoff = match ($period) {
            '1D' => now()->subDay(),
            '1W' => now()->subWeek(),
            '1M' => now()->subMonth(),
            default => null,
        };
        if ($cutoff) {
            $query->where('closed_at', '>=', $cutoff);
        }

        $paginator = $query->orderBy($orderBy, $order)->paginate($perPage);

        $data = collect($paginator->items())->map(function ($t) use ($walletLookup) {
            $wallet = $t->copied_from_wallet;
            $traderInfo = $wallet ? ($walletLookup[$wallet] ?? null) : null;

            return [
                'asset_id' => $t->asset_id,
                'market_slug' => $t->market_slug,
                'market_question' => $t->market_question,
                'market_image' => $t->market_image,
                'outcome' => $t->outcome,
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
        })->all();

        return response()->json([
            'data' => $data,
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'last_page' => $paginator->lastPage(),
        ]);
    }
}
