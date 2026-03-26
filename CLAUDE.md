# CLAUDE.md

This file provides guidance to Claude Code when working with this codebase.

## Project Overview

Polymarket Copy Bot v2 is a Laravel application that automatically copies trades from tracked Polymarket traders. It monitors wallet addresses, detects new trades via the Polymarket Data API, and replicates them using the CLOB (Central Limit Order Book) API. It includes a Vue.js dashboard for real-time P&L tracking and wallet management.

## Development Commands

```bash
./develop up -d                  # Start all containers (app, scheduler, db)
./develop down                   # Stop all containers
./develop art migrate --force    # Run database migrations
./develop art bot:poll           # Run one poll cycle manually
./develop art bot:update-prices  # Fetch current prices for all positions
./develop art bot:check-orders   # Poll pending orders, cancel expired ones
./develop art bot:check-resolved # Check for resolved markets and close them
./develop art bot:discover-wallets # Discover top traders from leaderboard
./develop art bot:migrate-from-python /tmp/bot_state.db  # One-time import from Python bot
./develop art tinker             # Laravel REPL
npm run dev                      # Vite dev server (inside container)
npm run build                    # Build frontend assets
```

## Architecture

### Docker Services

| Service     | Purpose                                                  | Port  |
|-------------|----------------------------------------------------------|-------|
| `app`       | Laravel app server (4 PHP workers)                       | 8085  |
| `scheduler` | Runs `php artisan schedule:work` for scheduled commands  | -     |
| `db`        | MariaDB 11.3                                             | 3309  |

### Scheduled Tasks (`routes/console.php`)

| Command              | Interval    | Purpose                                      |
|----------------------|-------------|----------------------------------------------|
| `bot:poll`           | Every 30s   | Fetch trades in rate-limited batches, copy them (~18-20s per cycle) |
| `bot:update-prices`  | Every 30s   | Update current prices in DB for the dashboard |
| `bot:check-orders`   | Every 30s   | Poll pending orders for fills, cancel expired (>10min) |
| `bot:check-resolved` | Every 5 min | Close positions in resolved markets           |
| `bot:check-wallets`  | Every 5 min | Auto-pause wallets with poor performance      |
| `bot:discover-wallets` | Every hour | Auto-discover top traders from Polymarket leaderboard |

### Services (`app/Services/`)

- **PolymarketClient** - CLOB API wrapper. Handles order placement (EIP-712 signing), midpoint prices, balance checks, and market resolution lookups via Gamma API. Caches prices (15s success, 5min failure) and resolution status (1hr resolved, 5min active). Also provides `getMarketSlug(tokenId)` to look up the Polymarket market slug for a CLOB token via Gamma API (cached 24h success, 1h failure). `placeOrder()` returns a structured result with `fill_price`, `status`, and `raw` response. For `matched` orders, `fill_price` is derived from `makingAmount/takingAmount`; for `live`/`delayed`/`dry_run` orders, it equals the requested limit price. `getOrder(orderId)` polls order status; `cancelOrder(orderId)` cancels resting orders.
- **TradeCopier** - Core trade replication logic. Applies filters (price tolerance, exposure cap, trading balance limit, sell filter), manages positions with weighted-average buy prices, records P&L, handles startup reconciliation and manual position closes. Fetches and stores `market_slug` on first BUY (post-order, non-blocking), copies it to `trade_history` on close via `recordPnl`. All post-order logic (buy price, exposure, sell P&L) uses the actual `fill_price` from the order response — not the tracked trader's execution price. For `live`/`delayed` orders, position updates are deferred — a `PendingOrder` is created and `processPendingOrders()` resolves it later (fill or cancel after TTL). Extracted `applyBuyFill()`/`applySellFill()` helpers shared by immediate fills and deferred fills. Trading balance and exposure cap checks include pending BUY order amounts to prevent overcommitting.
- **TradeTracker** - Polls Polymarket Data API for new trades from active (non-paused) wallets. Uses **rate-limited batched requests**: wallets are split into batches (default 15), each batch fires concurrently via `Http::pool`, with configurable delay between batches (default 500ms) to avoid 429 rate limiting. Wallets that receive a 429 are retried once after all primary batches complete. Batch-checks and batch-inserts SeenTrade records (chunked at 500) instead of per-trade DB queries. Seeds existing trades on first run to avoid copying historical trades. Prunes seen trades at 50k entries.
- **WalletScoring** - Computes advanced per-wallet metrics: profit factor, rolling-N expectancy, max drawdown %, consistency, and composite score (0-100). Single-query approach: fetches all trade_history ordered by wallet+closed_at, processes in PHP. Weighted score: Profit Factor 25%, Rolling Expectancy 25%, Win Rate 20%, Max Drawdown 15%, Consistency 15%. Used by both CheckWallets (auto-pause rules 5-6) and WalletReportController (score display).
- **LeaderboardDiscovery** - Fetches top traders from Polymarket leaderboard API (`/v1/leaderboard`). Filters by min PNL/volume thresholds, skips already-tracked wallets. Used by both the scheduled `bot:discover-wallets` command and `GET/POST /api/discover` endpoints.

### Controllers (`app/Http/Controllers/`)

- **DashboardController** - `GET /` renders the Vue dashboard. `GET /api/data` returns lightweight summary stats and balance info from DB using SQL aggregates (no positions/trades/wallets/wallet-report arrays — those use separate endpoints). Supports optional `wallets[]` and `period` (1D/1W/1M/ALL) query params for filtering: wallet filter applies to both positions and trade_history via `copied_from_wallet`; period filter applies to `trade_history.closed_at` for realized stats. When unfiltered, uses the fast PnlSummary singleton; when filtered, computes realized stats from raw `trade_history` table.
- **WalletReportController** - `GET /api/wallet-report` paginated per-wallet performance report using a single SQL query with LEFT JOIN subqueries for aggregation, sorting (ORDER BY), and pagination (LIMIT/OFFSET). WalletScoring is computed only for the current page's wallets (not all). `GET /api/wallet-report/summary` returns aggregate totals (profitable/losing/paused counts, best performer, average score) across all wallets — fetched separately from table data.
- **PositionController** - `GET /api/positions` paginated open positions (server-side sort/pagination). Supports optional `wallets[]` query param to filter by `copied_from_wallet`. `POST /api/close` manually closes a position at current midpoint. `POST /api/close-all` closes all open positions at current midpoints.
- **TradeHistoryController** - `GET /api/trades` paginated closed trades (server-side sort/pagination). Supports optional `wallets[]` and `period` (1D/1W/1M/ALL) query params — wallet filter on `copied_from_wallet`, period filter on `closed_at`.
- **WalletController** - CRUD for tracked wallets: `GET /api/wallets` (list), `POST /api/wallets` (add), `PUT /api/wallets` (update name/slug), `PATCH /api/wallets/pause` (pause/resume), `DELETE /api/wallets` (remove).
- **BalanceController** - `PUT /api/balance` updates the trading balance limit. Validates that limit cannot exceed real Polymarket balance when not in dry-run mode.
- **GlobalPauseController** - `POST /api/global-pause` toggles global bot pause state (stored in BotMeta). When paused, both polling and trade copying are skipped.
- **DiscoverController** - `GET /api/discover` returns leaderboard candidates (with already-tracked flags). `POST /api/discover` adds selected wallets by address array.

### Models (`app/Models/`)

| Model          | Purpose                                                        |
|----------------|----------------------------------------------------------------|
| `Position`     | Open positions (asset_id, market_slug, shares, buy_price, current_price) |
| `TrackedWallet`| Wallet addresses being tracked (address, name, profile_slug, is_paused, paused_at, pause_reason) |
| `TradeHistory` | Closed trade records (market_slug, buy/sell price, shares, pnl, timestamps) |
| `PnlSummary`   | Singleton row with aggregate P&L stats                         |
| `BotMeta`      | Key-value store for bot metadata (last_running_ts, polymarket_balance, trading_balance) |
| `PendingOrder` | Orders awaiting fill (live/delayed on CLOB orderbook)          |
| `SeenTrade`    | Transaction hashes of already-processed trades                 |

### Vue Frontend (`resources/js/`)

- **Dashboard.vue** - Main page with four tabs (Dashboard, Wallets, Report, Discover). Uses `v-if` for lazy tab rendering — inactive tabs unmount and stop polling. Auto-refreshes `/api/data` every 10 seconds. Per-tab `refreshTrigger` counters ensure only the active tab's components re-fetch data. Global "Pause Bot" and "Close All" buttons in the header, visible on all tabs. Maintains two data refs: `data` (always unfiltered, used by BalanceBar and header) and `statsData` (filtered when filters active, used by StatsCards via `displayData` computed). Both fetched in parallel on refresh. Filter state passed to tables via `tableFilterParams` computed.
- **BalanceBar.vue** - Balance management bar above stats cards. Shows Polymarket balance (read-only, N/A in dry-run), editable trading balance limit, available amount (Polymarket-style: limit - invested + realized P&L), and usage progress bar. Always uses unfiltered data — not affected by dashboard filters.
- **StatsFilter.vue** - Dashboard filter bar with wallet multi-select dropdown (searchable, checkboxes, fetches from `GET /api/wallets`) and time period toggle buttons (1D/1W/1M/All). Emits `change` event with `{ wallets: string[], period: string }`. "Clear filters" link shown when any filter is active. Wallet list refreshes on `refreshTrigger`.
- **StatsCards.vue** - Six stat cards: Combined P&L, Unrealized P&L, Realized P&L, Win Rate, Open Positions, Total Invested. Receives filtered or unfiltered data via `displayData` computed from Dashboard.
- **DataTable.vue** - Generic server-side paginated, sortable table component. Handles fetch, sort, pagination, per-page size selector (10/25/50/100), loading spinner, and empty state. Columns can be marked `sortable: false` to disable sorting (no cursor/arrow, click ignored). Exposes scoped slots (`#cell-{key}`, `#row-actions`, `#above-table`, `#extra-headers`) for custom cell rendering. Supports `extraParams` prop for appending arbitrary query params (including arrays) to API requests — watched via `JSON.stringify` to re-fetch with page reset only when values actually change. Used by PositionsTable, TradeHistoryTable, and WalletReport.
- **Pagination.vue** - Shared pagination (First/Prev/Next/Last) with per-page size dropdown. Used by all tables including WalletsManager.
- **PositionsTable.vue** - Uses DataTable with `apiUrl="/api/positions"`. Accepts `filters` prop passed through as `extraParams` to DataTable. Custom slots for Close button, status badges, null price handling, trader profile links, and market links (asset ID links to `polymarket.com/event/{slug}` when `market_slug` is available).
- **TradeHistoryTable.vue** - Uses DataTable with `apiUrl="/api/trades"`. Accepts `filters` prop passed through as `extraParams` to DataTable. Custom slots for trader link, P&L coloring, and market links (asset ID links to Polymarket when `market_slug` available).
- **WalletsManager.vue** - Add/edit/remove tracked wallets with inline editing for name and profile slug. Pause/Resume toggle per wallet with badge showing manual vs auto-pause. Self-fetching from `GET /api/wallets`. Client-side pagination with per-page selector.
- **WalletReport.vue** - Uses DataTable with `apiUrl="/api/wallet-report"`. Summary cards fetched independently from `GET /api/wallet-report/summary` (totals across all wallets, not just current page). Composite score column (0-100) with color gradient and hover tooltip showing score breakdown (profit factor, expectancy, win rate, drawdown, consistency). Pause/Resume buttons and win rate coloring.
- **WalletDiscovery.vue** - Leaderboard discovery UI. "Scan Leaderboard" button fetches candidates from `GET /api/discover` with configurable time period and category dropdowns. Shows ranked table with PNL, volume, Add/Tracked badges. "Add All" for bulk-add.

### Configuration (`config/polymarket.php`)

All trading parameters are configurable via `.env`:

| Env Variable                  | Default                         | Purpose                              |
|-------------------------------|---------------------------------|--------------------------------------|
| `POLYMARKET_PRIVATE_KEY`      | -                               | Wallet private key for signing       |
| `POLYMARKET_API_KEY`          | -                               | CLOB API key                         |
| `POLYMARKET_API_SECRET`       | -                               | CLOB API secret                      |
| `POLYMARKET_API_PASSPHRASE`   | -                               | CLOB API passphrase                  |
| `POLYMARKET_FIXED_AMOUNT_USDC`| 2                               | Fallback USDC per trade (when no score available) |
| `POLYMARKET_MAX_POSITION_USDC`| 100                             | Max exposure per market              |
| `POLYMARKET_PRICE_TOLERANCE`  | 0.03                            | Max price deviation before skipping  |
| `POLYMARKET_COPY_SELLS`       | true                            | Also replicate sell trades           |
| `POLYMARKET_DRY_RUN`          | true                            | Log only, no real orders             |
| `POLYMARKET_POLL_BATCH_SIZE`  | 15                              | Wallets per concurrent batch in poll cycle |
| `POLYMARKET_POLL_BATCH_DELAY_MS` | 500                          | Delay between batches in milliseconds |
| `POLYMARKET_PENDING_ORDER_TTL_MINUTES` | 10                     | Auto-cancel unfilled orders after N minutes |
| `POLYMARKET_AUTO_PAUSE_MAX_UNREALIZED_LOSS` | -50              | Unrealized P&L below which wallet is paused (Rule 1) |
| `POLYMARKET_AUTO_PAUSE_MIN_EXPOSURE` | 100                     | Min invested $ before exposure-loss rule applies (Rule 2) |
| `POLYMARKET_AUTO_PAUSE_MAX_EXPOSURE_LOSS_RATIO` | 0.20         | Unrealized loss / invested ratio that triggers pause (Rule 2) |
| `POLYMARKET_AUTO_PAUSE_BAD_RECORD_MIN_TRADES` | 5              | Min closed trades before track-record rule applies (Rule 3) |
| `POLYMARKET_AUTO_PAUSE_BAD_RECORD_MAX_WIN_RATE` | 40           | Win rate % below which wallet may be paused (Rule 3) |
| `POLYMARKET_AUTO_PAUSE_BAD_RECORD_MAX_LOSS` | -10              | Combined P&L below which wallet may be paused (Rule 3) |
| `POLYMARKET_AUTO_PAUSE_ZERO_WIN_MIN_TRADES` | 3                | Min closed trades with 0 wins to trigger pause (Rule 4) |
| `POLYMARKET_AUTO_PAUSE_ROLLING_EXPECTANCY_TRADES` | 20         | Rolling window size for expectancy check (Rule 5) |
| `POLYMARKET_AUTO_PAUSE_MIN_PROFIT_FACTOR` | 1.0                | Profit factor below which wallet is paused (Rule 6) |
| `POLYMARKET_AUTO_PAUSE_PROFIT_FACTOR_MIN_TRADES` | 10          | Min trades before profit factor rule applies (Rule 6) |
| `POLYMARKET_SCORE_MIN_TRADES` | 5                               | Min closed trades to compute composite score |
| `POLYMARKET_SIZING_HIGH_PCT`  | 0.50                            | % of available balance for score 70+ wallets |
| `POLYMARKET_SIZING_HIGH_MAX`  | 10                              | Max USDC per trade for score 70+ wallets |
| `POLYMARKET_SIZING_MID_PCT`   | 0.30                            | % of available balance for score 50-69 wallets |
| `POLYMARKET_SIZING_MID_MAX`   | 5                               | Max USDC per trade for score 50-69 wallets |
| `POLYMARKET_SIZING_LOW_PCT`   | 0.15                            | % of available balance for score 30-49 wallets |
| `POLYMARKET_SIZING_LOW_MAX`   | 3                               | Max USDC per trade for score 30-49 wallets |
| `POLYMARKET_SIZING_MIN`       | 1                               | Min USDC per trade (floor across all tiers) |
| `POLYMARKET_DISCOVER_MIN_PNL` | 500                              | Min PNL to qualify as discovery candidate     |
| `POLYMARKET_DISCOVER_MIN_VOLUME` | 10000                         | Min volume to qualify as discovery candidate  |
| `POLYMARKET_DISCOVER_TIME_PERIOD` | WEEK                         | Leaderboard time period (DAY/WEEK/MONTH/ALL)  |
| `POLYMARKET_DISCOVER_CATEGORY` | OVERALL                         | Leaderboard category filter                   |
| `POLYMARKET_DISCOVER_LIMIT` | 20                                 | Max candidates to fetch per scan              |
| `POLYMARKET_DISCOVER_MAX_AUTO_ADD` | 3                            | Max wallets auto-added per scheduled run      |

### External APIs

| API                    | Base URL                             | Used For                                     |
|------------------------|--------------------------------------|----------------------------------------------|
| CLOB API               | `https://clob.polymarket.com`        | Order placement, midpoints, balance, markets  |
| Data API               | `https://data-api.polymarket.com`    | Fetching trades by wallet, leaderboard        |
| Gamma API              | `https://gamma-api.polymarket.com`   | Market resolution status lookup, market slug lookup |

## Execution Flow

### Normal Operation (bot running)

1. **Every 30s** - `bot:poll`: Fetches trades from active (non-paused) tracked wallets in rate-limited batches (default 15 per batch, 500ms delay, with 429 retry). Detects new ones via `SeenTrade` deduplication, applies copy filters, places orders. Poll cycle takes ~18-20s with 264 wallets; `withoutOverlapping` mutex prevents stacking. Matched orders update positions immediately; live/delayed orders create PendingOrder records
2. **Every 30s** - `bot:update-prices`: Fetches midpoints for all open positions concurrently, updates DB. Falls back to resolution check if midpoint fails. Backfills `market_slug` for up to 5 positions per cycle that don't have one yet (via Gamma API, cached 24h). Also caches Polymarket USDC balance in BotMeta (skipped in dry-run)
3. **Every 30s** - `bot:check-orders`: Polls CLOB API for pending order status. Filled orders update positions with actual fill price. Orders older than 10min (configurable) are auto-cancelled
4. **Every 5min** - `bot:check-resolved`: Closes positions in resolved markets (WON=$1, LOST=$0, VOIDED=partial)
5. **Every 5min** - `bot:check-wallets`: Evaluates active wallets and auto-pauses if ANY rule triggers: (1) unrealized P&L < -$50, (2) invested > $100 and unrealized loss > 20% of invested, (3) 5+ closed trades with <40% win rate and combined P&L < -$10, (4) 3+ closed trades with zero wins, (5) 20+ trades with negative rolling expectancy, (6) 10+ trades with profit factor < 1.0
6. **Hourly** - `bot:discover-wallets`: Fetches Polymarket leaderboard, auto-adds up to 3 top traders meeting PNL/volume thresholds (skips already-tracked)
7. **Every 10s** - Dashboard auto-refreshes from DB (no API calls)

### On Startup

1. First `bot:poll` run seeds all existing trade IDs as "seen" (no copies)
2. Subsequent runs detect only new trades
3. Reconciliation checks if tracked traders sold while bot was offline

### Trade Copy Pipeline

1. Check global pause — skip all if bot is paused
2. Detect new trade from active (non-paused) tracked wallet
3. Filter: skip sells if disabled, skip zero price
4. Calculate size: dynamic amount based on wallet score and available balance (hybrid % with min/max caps), all held shares for sells
5. Price sanity: skip if midpoint deviates > 3 cents from trade price
6. Trading balance limit: skip if trade amount exceeds available capital (limit - invested + realized P&L)
7. Exposure cap: skip if would exceed $100 per market
8. Place order via CLOB API (or log in dry-run mode)
9. If matched/dry-run: extract actual fill price, update position immediately with weighted-average buy price, fetch market_slug
10. If live/delayed: create PendingOrder record, defer position update. `bot:check-orders` will poll for fill and apply update or auto-cancel after 10min TTL

## Key Design Decisions

- **Dashboard reads from DB only** - Prices are cached in the `positions` table by `bot:update-prices`. All API endpoints make zero external API calls, keeping response times ~20ms. Dashboard stats use SQL `SUM`/`COUNT` aggregates instead of loading rows into PHP.
- **Dashboard filtering** - Stats cards, positions table, and trade history table can be filtered by tracked wallets (multi-select) and time period (1D/1W/1M/All). Wallet filter applies `whereIn('copied_from_wallet', ...)` to positions, trade_history, and `/api/data` aggregates. Period filter applies `where('closed_at', '>=', cutoff)` to realized stats and trade history; unrealized stats show current state regardless of period (positions are live). BalanceBar always shows unfiltered data — Dashboard.vue maintains separate `data` (unfiltered) and `statsData` (filtered) refs, fetched in parallel via `Promise.all`. When no filters active, `/api/data` uses the fast PnlSummary singleton; when filtered, it computes from raw `trade_history`. DataTable `extraParams` watcher uses `JSON.stringify` comparison to avoid duplicate fetches on re-render.
- **Server-side pagination** - All tables use SQL-level sorting (ORDER BY) and pagination (LIMIT/OFFSET). Positions and trades use Eloquent `paginate()`. Wallet report uses a single query with LEFT JOIN subqueries. Only 10 rows per request instead of full datasets. WalletScoring is computed only for the current page's wallets. Columns not backed by SQL (e.g. composite_score) are marked `sortable: false` in the frontend.
- **Lazy tab loading** - Dashboard uses `v-if` (not `v-show`) for tab content — inactive tabs unmount completely, stopping their API polling. Each tab has its own `refreshTrigger` counter; only the active tab gets bumped on the 10s interval. On tab switch, the new tab's trigger is bumped so it fetches fresh data immediately. Result: on non-Dashboard tabs, only the lightweight `/api/data` polls (stats + balance); no positions/trades/wallets queries fire.
- **Trading balance limit** - User-configurable limit stored in `BotMeta`. Uses Polymarket-style accounting: `Available = Limit - Total Invested + Realized P&L`. Profits expand available capital, losses shrink it. BUY trades are skipped when trade amount exceeds available. In dry-run mode, the limit is freely editable. In live mode, it cannot exceed the real Polymarket balance.
- **Dynamic position sizing** - Hybrid % of available balance with min/max caps, scaled by wallet composite score. Three tiers: score 70+ (0.5%, max $10), score 50-69 (0.3%, max $5), score 30-49 (0.15%, max $3). All tiers have a $1 floor. Wallets with no score or score <30 use the fixed fallback amount ($2). As available balance grows, trade sizes grow proportionally; as it shrinks, sizes shrink automatically.
- **Dry-run by default** - `POLYMARKET_DRY_RUN=true` prevents accidental real trades. Must explicitly set to `false` for live trading.
- **First-run seeding** - On first poll, all existing trades are marked as "seen" to prevent copying historical trades.
- **Weighted-average buy price** - When buying the same asset multiple times, the buy price is the weighted average across all buys. Uses the actual fill price from the CLOB API response, not the tracked trader's execution price.
- **Actual fill price tracking** - `placeOrder()` returns a structured result with the actual execution price. For `matched` orders (immediately filled), the price is derived from the CLOB response's `makingAmount/takingAmount` (BUY: USDC spent ÷ shares received; SELL: USDC received ÷ shares sold). For `live` or `delayed` orders (sitting on orderbook, not yet filled), position updates are deferred via `PendingOrder` — the actual fill price is captured when `bot:check-orders` detects the order has been matched.
- **Pending order lifecycle** - Orders that don't fill immediately (CLOB status `live` or `delayed`) are tracked in the `pending_orders` table. `bot:check-orders` runs every 30s: polls `GET /order/{id}` for status, applies position updates on fill (with actual fill price), and auto-cancels orders older than 10min (configurable via `POLYMARKET_PENDING_ORDER_TTL_MINUTES`). Trading balance and exposure cap checks include pending BUY order amounts to prevent overcommitting capital. Positions are never updated prematurely — only confirmed fills modify shares/exposure/buy_price. Resolved pending orders are pruned after 7 days.
- **Market links** - Each position and trade history record stores a `market_slug` (nullable) fetched from the Gamma API on first BUY. The slug is cached 24h in Laravel cache and persisted in DB so dashboard API endpoints make zero external calls. `bot:update-prices` backfills slugs for existing positions (max 5/cycle). The slug is copied from position to trade_history on close. Frontend renders asset IDs as clickable links to `polymarket.com/event/{slug}` when available, with graceful fallback to plain text.
- **Market resolution handling** - Resolved markets (no orderbook) are detected and closed with correct payout ($1 for winners, $0 for losers, partial for voided).
- **Wallet pause/stop** - Wallets can be manually paused from the UI or auto-paused by `bot:check-wallets` based on configurable performance thresholds. Paused wallets stop being polled for new trades but existing positions remain open and manageable. Auto-paused wallets show a distinct red "Paused (Auto)" badge vs orange "Paused" for manual. Resuming is always manual via UI.
- **Wallet discovery** - Top traders auto-discovered hourly from the Polymarket leaderboard API (Data API `/v1/leaderboard`). Filters by PNL and volume thresholds. Max 3 auto-adds per run to prevent flooding. Manual discovery via the Discover tab allows scanning with custom time period/category and one-click adding.
- **Global pause & close all** - "Pause Bot" button in the header sets `BotMeta::global_paused`. Both `TradeTracker::poll()` and `TradeCopier::copy()` check this flag and skip all work when paused. Pulsing red "BOT PAUSED" badge shown in header. "Close All" button sells every open position at current midpoint with a confirmation dialog. Both buttons are always visible regardless of active tab.
- **Rate-limited polling** - Polymarket's Data API returns HTTP 429 when too many concurrent requests are fired. `TradeTracker` splits wallets into configurable batches (default 15 via `POLYMARKET_POLL_BATCH_SIZE`), fires each batch concurrently via `Http::pool`, then waits a configurable delay (default 500ms via `POLYMARKET_POLL_BATCH_DELAY_MS`) before the next batch. Wallets that receive a 429 are collected and retried once (with 2× delay) after all primary batches complete. `Http::pool` can return `ConnectionException` objects (not `Response`) on timeout — these are handled gracefully as null. A summary log (`poll_batch_summary`) reports total/failed/retried counts per cycle. With 264 wallets, this achieves ~91% success rate (vs ~55-70% with unbatched concurrent requests), at the cost of ~18-20s poll duration (still fits within the 30s interval with `withoutOverlapping` mutex).
- **Performance optimizations** - Database indexes on `copied_from_wallet` (positions + trade_history), `opened_at`/`closed_at` (trade_history), and `market_status` (positions). All aggregation uses SQL `GROUP BY` / `SUM` / `COUNT` instead of loading full tables into PHP. `TradeTracker` batch-checks/inserts SeenTrade records (chunked at 500). `SeenTrade::prune` uses a cutoff-ID delete instead of loading excess IDs into memory.

## File Structure

```
app/
  Console/Commands/
    BotPoll.php              # bot:poll - one poll cycle
    BotReconcile.php         # Startup reconciliation (called from BotPoll)
    CheckOrders.php          # bot:check-orders - poll pending orders, cancel expired
    CheckResolved.php        # bot:check-resolved - close resolved positions
    CheckWallets.php         # bot:check-wallets - auto-pause poor performers
    DiscoverWallets.php      # bot:discover-wallets - auto-discover top traders
    MigrateFromPython.php    # bot:migrate-from-python - one-time import
    UpdatePrices.php         # bot:update-prices - cache midpoints in DB
  DTOs/
    DetectedTrade.php        # Immutable trade data object
  Http/Controllers/
    BalanceController.php    # PUT /api/balance - trading balance limit
    DashboardController.php  # Dashboard page + /api/data (summary stats only)
    DiscoverController.php   # GET/POST /api/discover - leaderboard discovery
    GlobalPauseController.php # POST /api/global-pause - toggle bot pause
    PositionController.php   # GET /api/positions (paginated) + POST /api/close + POST /api/close-all
    WalletReportController.php # GET /api/wallet-report (paginated)
    TradeHistoryController.php # GET /api/trades (paginated)
    WalletController.php     # CRUD /api/wallets + PATCH /api/wallets/pause
  Models/
    BotMeta.php              # Key-value metadata store
    PendingOrder.php         # Orders awaiting fill on the CLOB orderbook
    PnlSummary.php           # Aggregate P&L singleton
    Position.php             # Open positions
    SeenTrade.php            # Deduplication of processed trades
    TrackedWallet.php        # Tracked wallet addresses (with pause state)
    TradeHistory.php         # Closed trade records
  Services/
    LeaderboardDiscovery.php # Leaderboard API + wallet discovery
    WalletScoring.php        # Composite score + advanced metrics per wallet
    PolymarketClient.php     # CLOB/Gamma API integration
    TradeCopier.php          # Trade replication + position management
    TradeTracker.php         # Wallet polling + trade detection
config/
  polymarket.php             # All trading configuration
database/migrations/         # 12 migration files (includes performance indexes, market_slug, pending_orders)
resources/js/
  Pages/Dashboard.vue        # Main page (tabs: Dashboard, Wallets, Report, Discover)
  Components/
    BalanceBar.vue           # Balance management (Polymarket + trading limit)
    DataTable.vue            # Generic server-side paginated/sortable table with slots
    Pagination.vue           # Shared pagination + per-page selector (10/25/50/100)
    StatsCards.vue            # P&L stat cards
    StatsFilter.vue          # Wallet multi-select + time period filter (1D/1W/1M/All)
    PositionsTable.vue       # Open positions (uses DataTable)
    TradeHistoryTable.vue    # Closed trades (uses DataTable)
    WalletDiscovery.vue      # Leaderboard discovery + add wallets
    WalletsManager.vue       # Wallet CRUD UI (client-side pagination)
    WalletReport.vue         # Per-wallet performance report (uses DataTable)
  utils/
    formatters.js            # Shared formatting: fmtUsd, pnlClass, fmtDate, shortId, traderLabel, traderUrl, marketUrl
routes/
  api.php                    # API endpoints
  console.php                # Scheduled tasks
  web.php                    # Web routes
docker/
  wait-for-db.php            # DB readiness check script
Dockerfile                   # PHP 8.4 + Node 20
docker-compose.yml           # app + scheduler + db
```
