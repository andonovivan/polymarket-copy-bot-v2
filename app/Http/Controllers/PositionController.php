<?php

namespace App\Http\Controllers;

use App\Models\Position;
use App\Models\TrackedWallet;
use App\Services\TradeCopier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PositionController extends Controller
{
    /**
     * Paginated list of open positions.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->input('per_page', 10), 100);
        $sort = $request->input('sort', 'opened_at');
        $order = strtolower($request->input('order', 'desc')) === 'asc' ? 'asc' : 'desc';

        // Whitelist sortable columns — map frontend keys to DB expressions.
        $sortable = [
            'trader_name' => 'copied_from_wallet',
            'asset_id' => 'asset_id',
            'shares' => 'shares',
            'buy_price' => 'buy_price',
            'current_price' => 'current_price',
            'cost' => DB::raw('buy_price * shares'),
            'current_value' => DB::raw('current_price * shares'),
            'unrealized_pnl' => DB::raw('(current_price - buy_price) * shares'),
            'opened_at' => 'opened_at',
            'status' => 'market_status',
        ];

        $orderBy = $sortable[$sort] ?? 'opened_at';

        $walletLookup = TrackedWallet::all()->keyBy('address')->map(fn ($w) => [
            'name' => $w->name,
            'profile_slug' => $w->profile_slug,
        ])->all();

        $query = Position::where('shares', '>', 0);

        // Optional wallet filter.
        $wallets = $request->input('wallets', []);
        if (!empty($wallets)) {
            $query->whereIn('copied_from_wallet', $wallets);
        }

        $paginator = $query->orderBy($orderBy, $order)->paginate($perPage);

        $data = collect($paginator->items())->map(function ($pos) use ($walletLookup) {
            $buyPrice = (float) $pos->buy_price;
            $shares = (float) $pos->shares;
            $currentPrice = $pos->current_price;
            $status = $pos->market_status ?? 'active';
            $cost = $buyPrice * $shares;

            $currentValue = $currentPrice !== null ? $currentPrice * $shares : null;
            $unrealized = $currentValue !== null ? $currentValue - $cost : null;

            $wallet = $pos->copied_from_wallet;
            $traderInfo = $wallet ? ($walletLookup[$wallet] ?? null) : null;

            return [
                'asset_id' => $pos->asset_id,
                'market_slug' => $pos->market_slug,
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
        })->all();

        return response()->json([
            'data' => $data,
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'last_page' => $paginator->lastPage(),
        ]);
    }

    /**
     * Manually close an open position at the current midpoint.
     */
    public function close(Request $request, TradeCopier $copier): JsonResponse
    {
        $assetId = $request->input('asset_id', '');
        if (! $assetId) {
            return response()->json(['error' => 'Missing asset_id'], 400);
        }

        $result = $copier->closePosition($assetId);
        $status = isset($result['ok']) ? 200 : 400;

        return response()->json($result, $status);
    }

    /**
     * Close all open positions at current midpoint prices.
     */
    public function closeAll(TradeCopier $copier): JsonResponse
    {
        $positions = Position::where('shares', '>', 0)->get();
        if ($positions->isEmpty()) {
            return response()->json(['ok' => true, 'closed' => 0, 'failed' => 0]);
        }

        $closed = 0;
        $failed = 0;

        foreach ($positions as $position) {
            $result = $copier->closePosition($position->asset_id);
            if (isset($result['ok'])) {
                $closed++;
            } else {
                $failed++;
            }
        }

        return response()->json([
            'ok' => true,
            'closed' => $closed,
            'failed' => $failed,
        ]);
    }
}
