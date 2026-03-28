<?php

namespace App\Http\Controllers;

use App\Models\TrackedWallet;
use App\Services\PolymarketClient;
use App\Services\Setting;
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

        // Compute average composite score only for wallets with activity.
        $activeAddresses = array_unique(array_merge(
            $realizedByWallet->keys()->all(),
            $unrealizedByWallet->keys()->all(),
        ));
        $walletScores = ! empty($activeAddresses) ? (new WalletScoring)->compute($activeAddresses) : [];
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
     * Paginated wallet performance report.
     *
     * Uses a single SQL query with LEFT JOINs for aggregation, sorting, and
     * pagination (LIMIT/OFFSET). WalletScoring is computed only for the
     * current page's wallets.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->input('per_page', 10), 100);
        $page = max((int) $request->input('page', 1), 1);
        $sort = $request->input('sort', 'combined_pnl');
        $order = strtolower($request->input('order', 'desc')) === 'asc' ? 'asc' : 'desc';

        // Whitelist sortable columns — map frontend keys to SQL expressions.
        $sortable = [
            'name' => 'tw.name',
            'combined_pnl' => 'combined_pnl',
            'realized_pnl' => 'realized_pnl',
            'unrealized_pnl' => 'unrealized_pnl',
            'win_rate' => 'win_rate',
            'total_trades' => 'total_trades',
            'open_positions' => 'open_positions',
            'total_invested' => 'total_invested',
            'is_paused' => 'tw.is_paused',
        ];

        $orderBy = $sortable[$sort] ?? 'combined_pnl';

        // Single query: tracked_wallets LEFT JOIN aggregated trade_history and positions.
        // Only returns wallets with at least 1 trade or 1 open position.
        $query = DB::table('tracked_wallets as tw')
            ->leftJoinSub(
                DB::table('trade_history')
                    ->select('copied_from_wallet')
                    ->selectRaw('COUNT(*) as total_trades')
                    ->selectRaw('SUM(CASE WHEN pnl >= 0 THEN 1 ELSE 0 END) as winning_trades')
                    ->selectRaw('SUM(CASE WHEN pnl < 0 THEN 1 ELSE 0 END) as losing_trades')
                    ->selectRaw('SUM(pnl) as realized_pnl')
                    ->groupBy('copied_from_wallet'),
                'th',
                'tw.address',
                '=',
                'th.copied_from_wallet'
            )
            ->leftJoinSub(
                DB::table('positions')
                    ->select('copied_from_wallet')
                    ->selectRaw('COUNT(*) as open_positions')
                    ->selectRaw('SUM(buy_price * shares) as total_invested')
                    ->selectRaw('SUM(CASE WHEN current_price IS NOT NULL THEN (current_price - buy_price) * shares ELSE 0 END) as unrealized_pnl')
                    ->where('shares', '>', 0)
                    ->groupBy('copied_from_wallet'),
                'pos',
                'tw.address',
                '=',
                'pos.copied_from_wallet'
            )
            ->select(
                'tw.address',
                'tw.name',
                'tw.profile_slug',
                'tw.is_paused',
                'tw.paused_at',
                'tw.pause_reason',
            )
            ->selectRaw('COALESCE(th.total_trades, 0) as total_trades')
            ->selectRaw('COALESCE(th.winning_trades, 0) as winning_trades')
            ->selectRaw('COALESCE(th.losing_trades, 0) as losing_trades')
            ->selectRaw('ROUND(COALESCE(th.realized_pnl, 0), 4) as realized_pnl')
            ->selectRaw('COALESCE(pos.open_positions, 0) as open_positions')
            ->selectRaw('ROUND(COALESCE(pos.total_invested, 0), 4) as total_invested')
            ->selectRaw('ROUND(COALESCE(pos.unrealized_pnl, 0), 4) as unrealized_pnl')
            ->selectRaw('ROUND(COALESCE(th.realized_pnl, 0) + COALESCE(pos.unrealized_pnl, 0), 4) as combined_pnl')
            ->selectRaw('CASE WHEN COALESCE(th.total_trades, 0) > 0 THEN ROUND(COALESCE(th.winning_trades, 0) / th.total_trades * 100, 1) ELSE 0 END as win_rate')
            // Filter: only wallets with activity.
            ->where(function ($q) {
                $q->where(DB::raw('COALESCE(th.total_trades, 0)'), '>', 0)
                    ->orWhere(DB::raw('COALESCE(pos.open_positions, 0)'), '>', 0);
            });

        // Count total matching rows (before pagination).
        $total = $query->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $offset = ($page - 1) * $perPage;

        // Fetch current page with sorting.
        $pageRows = $query
            ->orderBy(DB::raw($orderBy), $order)
            ->offset($offset)
            ->limit($perPage)
            ->get();

        // Compute WalletScoring only for this page's wallets.
        $pageAddresses = $pageRows->pluck('address')->all();
        $walletScores = ! empty($pageAddresses) ? (new WalletScoring)->compute($pageAddresses) : [];

        // Map to response format.
        $data = $pageRows->map(function ($r) use ($walletScores) {
            $scores = $walletScores[$r->address] ?? null;

            return [
                'address' => $r->address,
                'name' => $r->name,
                'profile_slug' => $r->profile_slug,
                'is_paused' => (bool) $r->is_paused,
                'paused_at' => $r->paused_at ? strtotime($r->paused_at) : null,
                'pause_reason' => $r->pause_reason,
                'realized_pnl' => (float) $r->realized_pnl,
                'unrealized_pnl' => (float) $r->unrealized_pnl,
                'combined_pnl' => (float) $r->combined_pnl,
                'total_trades' => (int) $r->total_trades,
                'winning_trades' => (int) $r->winning_trades,
                'losing_trades' => (int) $r->losing_trades,
                'open_positions' => (int) $r->open_positions,
                'total_invested' => (float) $r->total_invested,
                'win_rate' => (float) $r->win_rate,
                'composite_score' => $scores['composite_score'] ?? null,
                'profit_factor' => $scores['profit_factor'] ?? null,
                'rolling_expectancy' => $scores['rolling_expectancy'] ?? null,
                'max_drawdown_pct' => $scores['max_drawdown_pct'] ?? null,
                'consistency' => $scores['consistency'] ?? null,
                'score_breakdown' => $scores['score_breakdown'] ?? null,
            ];
        })->all();

        return response()->json([
            'data' => $data,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'last_page' => $lastPage,
        ]);
    }

    /**
     * Performance report grouped by market category.
     *
     * Aggregates trade_history and positions by mapping each market's event tags
     * to a primary category (first match from config market_category_tags).
     */
    public function categoryReport(Request $request): JsonResponse
    {
        $sort = $request->input('sort', 'combined_pnl');
        $order = strtolower($request->input('order', 'desc')) === 'asc' ? 'asc' : 'desc';

        $client = app(PolymarketClient::class);
        $tagMapping = config('polymarket.market_category_tags', []);
        $categoryLabels = [
            'crypto' => 'Crypto',
            'politics' => 'Politics',
            'sports' => 'Sports',
            'pop_culture' => 'Pop Culture',
            'business' => 'Business & Finance',
            'science' => 'Science & Weather',
            'other' => 'Other',
        ];

        // Collect all unique slugs from positions + trade_history.
        $positionSlugs = DB::table('positions')
            ->where('shares', '>', 0)
            ->whereNotNull('market_slug')
            ->distinct()
            ->pluck('market_slug');

        $tradeSlugs = DB::table('trade_history')
            ->whereNotNull('market_slug')
            ->distinct()
            ->pluck('market_slug');

        $allSlugs = $positionSlugs->merge($tradeSlugs)->unique()->values();

        // Map each slug to its primary category using only cached tags
        // (avoids slow API calls — uncached slugs go to "other").
        $slugToCategory = [];
        foreach ($allSlugs as $slug) {
            $cached = \Illuminate\Support\Facades\Cache::get("event_tags:{$slug}");
            $tags = is_array($cached) ? $cached : [];
            $slugToCategory[$slug] = $this->mapTagsToCategory($tags, $tagMapping);
        }

        // Initialize category stats.
        $categories = [];
        foreach (array_keys($categoryLabels) as $cat) {
            $categories[$cat] = [
                'category' => $cat,
                'label' => $categoryLabels[$cat],
                'realized_pnl' => 0,
                'unrealized_pnl' => 0,
                'combined_pnl' => 0,
                'total_trades' => 0,
                'winning_trades' => 0,
                'losing_trades' => 0,
                'open_positions' => 0,
                'total_invested' => 0,
                'win_rate' => 0,
                'is_enabled' => (bool) Setting::get("category_{$cat}", true),
            ];
        }

        // Aggregate trade_history by category.
        $trades = DB::table('trade_history')
            ->select('market_slug', DB::raw('COUNT(*) as cnt'), DB::raw('SUM(CASE WHEN pnl >= 0 THEN 1 ELSE 0 END) as wins'), DB::raw('SUM(CASE WHEN pnl < 0 THEN 1 ELSE 0 END) as losses'), DB::raw('SUM(pnl) as realized'))
            ->whereNotNull('market_slug')
            ->groupBy('market_slug')
            ->get();

        foreach ($trades as $row) {
            $cat = $slugToCategory[$row->market_slug] ?? 'other';
            $categories[$cat]['total_trades'] += (int) $row->cnt;
            $categories[$cat]['winning_trades'] += (int) $row->wins;
            $categories[$cat]['losing_trades'] += (int) $row->losses;
            $categories[$cat]['realized_pnl'] += (float) $row->realized;
        }

        // Trades without market_slug go to "other".
        $noSlugTrades = DB::table('trade_history')
            ->selectRaw('COUNT(*) as cnt, SUM(CASE WHEN pnl >= 0 THEN 1 ELSE 0 END) as wins, SUM(CASE WHEN pnl < 0 THEN 1 ELSE 0 END) as losses, SUM(pnl) as realized')
            ->whereNull('market_slug')
            ->first();

        if ($noSlugTrades && $noSlugTrades->cnt > 0) {
            $categories['other']['total_trades'] += (int) $noSlugTrades->cnt;
            $categories['other']['winning_trades'] += (int) $noSlugTrades->wins;
            $categories['other']['losing_trades'] += (int) $noSlugTrades->losses;
            $categories['other']['realized_pnl'] += (float) $noSlugTrades->realized;
        }

        // Aggregate positions by category.
        $positions = DB::table('positions')
            ->select('market_slug', DB::raw('COUNT(*) as cnt'), DB::raw('SUM(buy_price * shares) as invested'), DB::raw('SUM(CASE WHEN current_price IS NOT NULL THEN (current_price - buy_price) * shares ELSE 0 END) as unrealized'))
            ->where('shares', '>', 0)
            ->whereNotNull('market_slug')
            ->groupBy('market_slug')
            ->get();

        foreach ($positions as $row) {
            $cat = $slugToCategory[$row->market_slug] ?? 'other';
            $categories[$cat]['open_positions'] += (int) $row->cnt;
            $categories[$cat]['total_invested'] += (float) $row->invested;
            $categories[$cat]['unrealized_pnl'] += (float) $row->unrealized;
        }

        // Positions without market_slug go to "other".
        $noSlugPositions = DB::table('positions')
            ->selectRaw('COUNT(*) as cnt, SUM(buy_price * shares) as invested, SUM(CASE WHEN current_price IS NOT NULL THEN (current_price - buy_price) * shares ELSE 0 END) as unrealized')
            ->where('shares', '>', 0)
            ->whereNull('market_slug')
            ->first();

        if ($noSlugPositions && $noSlugPositions->cnt > 0) {
            $categories['other']['open_positions'] += (int) $noSlugPositions->cnt;
            $categories['other']['total_invested'] += (float) $noSlugPositions->invested;
            $categories['other']['unrealized_pnl'] += (float) $noSlugPositions->unrealized;
        }

        // Compute combined P&L, win rate, and round values.
        $data = [];
        foreach ($categories as &$cat) {
            $cat['combined_pnl'] = round($cat['realized_pnl'] + $cat['unrealized_pnl'], 4);
            $cat['realized_pnl'] = round($cat['realized_pnl'], 4);
            $cat['unrealized_pnl'] = round($cat['unrealized_pnl'], 4);
            $cat['total_invested'] = round($cat['total_invested'], 4);
            $cat['win_rate'] = $cat['total_trades'] > 0
                ? round($cat['winning_trades'] / $cat['total_trades'] * 100, 1)
                : 0;

            // Only include categories with activity.
            if ($cat['total_trades'] > 0 || $cat['open_positions'] > 0) {
                $data[] = $cat;
            }
        }

        // Sort.
        $sortable = ['combined_pnl', 'realized_pnl', 'unrealized_pnl', 'win_rate', 'total_trades', 'open_positions', 'total_invested', 'label', 'is_enabled'];
        $sortKey = in_array($sort, $sortable) ? $sort : 'combined_pnl';
        usort($data, function ($a, $b) use ($sortKey, $order) {
            $va = $a[$sortKey] ?? 0;
            $vb = $b[$sortKey] ?? 0;
            $cmp = $va <=> $vb;

            return $order === 'asc' ? $cmp : -$cmp;
        });

        return response()->json([
            'data' => $data,
            'total' => count($data),
            'page' => 1,
            'per_page' => 100,
            'last_page' => 1,
        ]);
    }

    /**
     * Map event tags to a primary category (first match from config mapping).
     */
    private function mapTagsToCategory(array $tags, array $tagMapping): string
    {
        foreach ($tagMapping as $category => $categoryTags) {
            if (! empty(array_intersect($tags, $categoryTags))) {
                return $category;
            }
        }

        return 'other';
    }
}
