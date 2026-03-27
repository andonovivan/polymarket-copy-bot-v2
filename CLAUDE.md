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

- **PolymarketClient** - CLOB API wrapper. Handles order placement (EIP-712 signing), midpoint prices, balance checks, and market resolution lookups via Gamma API. Caches prices (15s success, 5min failure) and resolution status (1hr resolved, 5min active). `getMarketMetadata(tokenId)` fetches full market info from Gamma API — returns `{slug, question, image, outcome}` (cached 24h success, 1h failure). Determines outcome by matching token ID against the market's `clobTokenIds` array. For grouped markets (has `groupItemTitle`), uses event title as question and `groupItemTitle` as outcome (e.g. "280-299" instead of "Yes"); "No" side becomes "No 280-299". For standalone markets, uses market question and raw Yes/No outcome. `getMarketSlug(tokenId)` is a thin wrapper returning just the slug. `placeOrder()` returns a structured result with `fill_price`, `status`, and `raw` response. For `matched` orders, `fill_price` is derived from `makingAmount/takingAmount`; for `live`/`delayed`/`dry_run` orders, it equals the requested limit price. `getOrder(orderId)` polls order status; `cancelOrder(orderId)` cancels resting orders.
- **TradeCopier** - Core trade replication logic. Applies filters (price tolerance, exposure cap, trading balance limit, sell filter), manages positions with weighted-average buy prices, records P&L, handles startup reconciliation and manual position closes. Fetches and stores market metadata (slug, question, image, outcome) via `getMarketMetadata()` on first BUY (post-order, non-blocking), copies all metadata to `trade_history` on close via `recordPnl`. All post-order logic (buy price, exposure, sell P&L) uses the actual `fill_price` from the order response — not the tracked trader's execution price. For `live`/`delayed` orders, position updates are deferred — a `PendingOrder` is created and `processPendingOrders()` resolves it later (fill or cancel after TTL). Extracted `applyBuyFill()`/`applySellFill()` helpers shared by immediate fills and deferred fills. Trading balance and exposure cap checks include pending BUY order amounts to prevent overcommitting.
- **TradeTracker** - Polls Polymarket Data API for new trades from active (non-paused) wallets. Uses **rate-limited batched requests**: wallets are split into batches (default 15), each batch fires concurrently via `Http::pool`, with configurable delay between batches (default 500ms) to avoid 429 rate limiting. Wallets that receive a 429 are retried once after all primary batches complete. Uses **per-wallet timestamp watermarks** (`last_trade_ts` on `TrackedWallet`) for deduplication — only trades with a timestamp strictly greater than the wallet's watermark are treated as new. Newly added wallets (null watermark) are seeded on first poll: the watermark is set to the highest trade timestamp without copying any trades. **Tiered polling**: wallets with `last_trade_ts` older than N days (default 3 via `POLYMARKET_INACTIVE_WALLET_DAYS`) are considered inactive and only polled once per hour (configurable via `POLYMARKET_INACTIVE_POLL_INTERVAL_SECONDS`). If an inactive wallet trades again, its watermark updates and it automatically returns to the active tier. This significantly reduces API load when many tracked wallets are dormant.
- **WalletScoring** - Computes advanced per-wallet metrics: profit factor, rolling-N expectancy, max drawdown %, consistency, and composite score (0-100). Single-query approach: fetches all trade_history ordered by wallet+closed_at, processes in PHP. Weighted score: Profit Factor 25%, Rolling Expectancy 25%, Win Rate 20%, Max Drawdown 15%, Consistency 15%. Used by both CheckWallets (auto-pause rules 5-6) and WalletReportController (score display).
- **LeaderboardDiscovery** - Fetches top traders from Polymarket leaderboard API (`/v1/leaderboard`). Filters by min PNL/volume thresholds, skips already-tracked wallets. Used by both the scheduled `bot:discover-wallets` command and `GET/POST /api/discover` endpoints.
- **Setting** - Runtime-configurable settings with DB override and env/config fallback. Stores overrides in `BotMeta` with `setting:` key prefix. `Setting::get($key)` checks BotMeta first, falls back to `config('polymarket.{key}')`. Exposes a schema of 32 configurable parameters across 5 groups (sizing, limits, behavior, polling, auto_pause). Includes `fixed_amount_override` — when set, bypasses dynamic sizing and uses a fixed USDC amount for all BUY trades. The auto_pause group exposes all 11 auto-pause thresholds (grace period, unrealized loss, exposure ratio, bad record, zero wins, rolling expectancy, profit factor). Used by TradeCopier, TradeTracker, BotPoll, CheckWallets, and controllers instead of direct `config()` calls for the exposed settings.

### Controllers (`app/Http/Controllers/`)

- **DashboardController** - `GET /` renders the Vue dashboard. `GET /api/data` returns lightweight summary stats and balance info from DB using SQL aggregates (no positions/trades/wallets/wallet-report arrays — those use separate endpoints). Supports optional `wallets[]` and `period` (1D/1W/1M/ALL) query params for filtering: wallet filter applies to both positions and trade_history via `copied_from_wallet`; period filter applies to `trade_history.closed_at` for realized stats. When unfiltered, uses the fast PnlSummary singleton; when filtered, computes realized stats from raw `trade_history` table.
- **WalletReportController** - `GET /api/wallet-report` paginated per-wallet performance report using a single SQL query with LEFT JOIN subqueries for aggregation, sorting (ORDER BY), and pagination (LIMIT/OFFSET). WalletScoring is computed only for the current page's wallets (not all). `GET /api/wallet-report/summary` returns aggregate totals (profitable/losing/paused counts, best performer, average score) across all wallets — fetched separately from table data.
- **PositionController** - `GET /api/positions` paginated open positions (server-side sort/pagination). Supports optional `wallets[]` query param to filter by `copied_from_wallet`. `POST /api/close` manually closes a position at current midpoint. `POST /api/close-all` closes all open positions at current midpoints.
- **TradeHistoryController** - `GET /api/trades` paginated closed trades (server-side sort/pagination). Supports optional `wallets[]` and `period` (1D/1W/1M/ALL) query params — wallet filter on `copied_from_wallet`, period filter on `closed_at`.
- **ActivityController** - `GET /api/activity` unified chronological activity feed. Uses a UNION query across positions (Buy events) and trade_history (Buy + Sell/Redeem events). Each open position generates one Buy event; each closed trade generates a Buy event (at `opened_at`) and a Sell or Redeem event (at `closed_at`). Redeem is inferred when `sell_price >= 0.999` or `<= 0.001` (market resolution). Supports `wallets[]` and `period` filters, sortable by `event_ts` or `amount`. Paginated with trader lookup.
- **WalletController** - CRUD for tracked wallets: `GET /api/wallets` (list), `POST /api/wallets` (add), `PUT /api/wallets` (update name/slug), `PATCH /api/wallets/pause` (pause/resume), `DELETE /api/wallets` (remove), `DELETE /api/wallets/bulk` (bulk delete by address array), `PATCH /api/wallets/bulk-pause` (bulk pause/resume by address array).
- **BalanceController** - `PUT /api/balance` updates the trading balance limit. Validates that limit cannot exceed real Polymarket balance when not in dry-run mode.
- **GlobalPauseController** - `POST /api/global-pause` toggles global bot pause state (stored in BotMeta). When paused, both polling and trade copying are skipped.
- **DiscoverController** - `GET /api/discover` returns leaderboard candidates (with already-tracked flags). `POST /api/discover` adds selected wallets by address array.
- **SettingsController** - `GET /api/settings` returns all configurable settings with current values, env defaults, and override status. `PUT /api/settings` bulk-updates settings (validated by type). `DELETE /api/settings/{key}` resets a single setting to env default. `POST /api/reset-data` resets all data (positions, trades, pending orders, seen trades, PnlSummary, BotMeta runtime keys, wallet watermarks) while keeping tracked wallets and settings intact.

### Models (`app/Models/`)

| Model          | Purpose                                                        |
|----------------|----------------------------------------------------------------|
| `Position`     | Open positions (asset_id, market_slug, market_question, market_image, outcome, shares, buy_price, current_price) |
| `TrackedWallet`| Wallet addresses being tracked (address, name, profile_slug, is_paused, paused_at, pause_reason, last_trade_ts) |
| `TradeHistory` | Closed trade records (market_slug, market_question, market_image, outcome, buy/sell price, shares, pnl, timestamps) |
| `PnlSummary`   | Singleton row with aggregate P&L stats                         |
| `BotMeta`      | Key-value store for bot metadata (last_running_ts, polymarket_balance, trading_balance) |
| `PendingOrder` | Orders awaiting fill (live/delayed on CLOB orderbook)          |
| `SeenTrade`    | Legacy table (replaced by per-wallet `last_trade_ts` watermarks) |

### Vue Frontend (`resources/js/`)

- **Dashboard.vue** - Main page with five tabs (Dashboard, Wallets, Report, Discover, Settings). Uses `v-if` for lazy tab rendering — inactive tabs unmount and stop polling. Auto-refreshes `/api/data` every 10 seconds. Per-tab `refreshTrigger` counters ensure only the active tab's components re-fetch data. Global "Pause Bot" and "Close All" buttons in the header, visible on all tabs. Maintains two data refs: `data` (always unfiltered, used by BalanceBar and header) and `statsData` (filtered when filters active, used by StatsCards via `displayData` computed). Both fetched in parallel on refresh. Filter state passed to ActivityTable via `tableFilterParams` computed.
- **BalanceBar.vue** - Balance management bar above stats cards. Shows Polymarket balance (read-only, N/A in dry-run), editable trading balance limit, available amount (Polymarket-style: limit - invested + realized P&L), and usage progress bar. Always uses unfiltered data — not affected by dashboard filters.
- **StatsFilter.vue** - Dashboard filter bar with wallet multi-select dropdown (searchable, checkboxes, fetches from `GET /api/wallets`) and time period toggle buttons (1D/1W/1M/All). Emits `change` event with `{ wallets: string[], period: string }`. "Clear filters" link shown when any filter is active. Wallet list refreshes on `refreshTrigger`.
- **StatsCards.vue** - Six stat cards: Combined P&L, Unrealized P&L, Realized P&L, Win Rate, Open Positions, Total Invested. Receives filtered or unfiltered data via `displayData` computed from Dashboard.
- **DataTable.vue** - Generic server-side paginated, sortable table component. Handles fetch, sort, pagination, per-page size selector (10/25/50/100), loading spinner, and empty state. Columns can be marked `sortable: false` to disable sorting (no cursor/arrow, click ignored). Exposes scoped slots (`#cell-{key}`, `#row-actions`, `#above-table`, `#extra-headers`) for custom cell rendering. Supports `extraParams` prop for appending arbitrary query params (including arrays) to API requests — watched via `JSON.stringify` to re-fetch with page reset only when values actually change. Used by ActivityTable and WalletReport.
- **Pagination.vue** - Shared pagination (First/Prev/Next/Last) with per-page size dropdown. Used by all tables including WalletsManager.
- **ActivityTable.vue** - Unified Polymarket-style positions/activity view with Positions/Activity sub-tabs. Positions tab shows open positions with market image, question text, outcome badge (Yes/No with price), shares, avg/current price, value with P&L (amount + %), and Close button. Activity tab is a chronological feed of all Buy/Sell/Redeem events (matching Polymarket's layout) with TYPE, MARKET, TRADER, and AMOUNT columns. Each closed trade generates two activity events (Buy at opened_at + Sell/Redeem at closed_at); open positions generate a Buy event. Redeem = market resolution (sell_price ~$1 or ~$0). Amount shows dollar value with relative time ("5h ago"). Uses `/api/activity` (UNION query across positions + trade_history). Replaces the previous separate PositionsTable and TradeHistoryTable components.
- **PositionsTable.vue** - Legacy component (replaced by ActivityTable on the Dashboard). Uses DataTable with `apiUrl="/api/positions"`.
- **TradeHistoryTable.vue** - Legacy component (replaced by ActivityTable on the Dashboard). Uses DataTable with `apiUrl="/api/trades"`.
- **WalletsManager.vue** - Add/edit/remove tracked wallets with inline editing for name and profile slug. Pause/Resume toggle per wallet with badge showing manual vs auto-pause. Checkbox selection with select-all-on-page, bulk Pause/Resume/Delete actions for selected wallets. Self-fetching from `GET /api/wallets`. Client-side pagination with per-page selector.
- **WalletReport.vue** - Uses DataTable with `apiUrl="/api/wallet-report"`. Summary cards fetched independently from `GET /api/wallet-report/summary` (totals across all wallets, not just current page). Composite score column (0-100) with color gradient and hover tooltip showing score breakdown (profit factor, expectancy, win rate, drawdown, consistency). Pause/Resume buttons and win rate coloring.
- **WalletDiscovery.vue** - Leaderboard discovery UI. "Scan Leaderboard" button fetches candidates from `GET /api/discover` with configurable time period and category dropdowns. Shows ranked table with PNL, volume, Add/Tracked badges. "Add All" for bulk-add.
- **Settings.vue** - Bot settings UI with grouped form sections (Trade Sizing, Risk Limits, Trade Behavior, Polling, Auto-Pause Rules). Each setting shows current value, env default, and "custom" badge when overridden. Boolean settings use toggle switches. "Save Changes" button with dirty-state tracking. Per-field "reset" to revert to env default. Fixed Amount Override toggle at the top of sizing section — when set, bypasses all dynamic sizing logic. Auto-Pause Rules section exposes all 11 thresholds (grace period, unrealized loss, exposure ratio, bad record, zero wins, rolling expectancy, profit factor) — description notes that grace period skips rules 3-6 until enough trades. Danger Zone section with "Reset All Data" button (confirmation dialog) that clears all positions, trades, and stats while keeping wallets and settings.

### Configuration (`config/polymarket.php`)

All trading parameters are configurable via `.env`:

| Env Variable                  | Default                         | Purpose                              |
|-------------------------------|---------------------------------|--------------------------------------|
| `POLYMARKET_PRIVATE_KEY`      | -                               | Wallet private key for signing       |
| `POLYMARKET_API_KEY`          | -                               | CLOB API key                         |
| `POLYMARKET_API_SECRET`       | -                               | CLOB API secret                      |
| `POLYMARKET_API_PASSPHRASE`   | -                               | CLOB API passphrase                  |
| `POLYMARKET_FIXED_AMOUNT_USDC`| 2                               | Fallback USDC per trade (when no score available) |
| `POLYMARKET_MAX_POSITION_USDC`| 10                              | Max exposure per market              |
| `POLYMARKET_MAX_WALLET_EXPOSURE_USDC` | 20                      | Max total exposure per tracked wallet |
| `POLYMARKET_PRICE_TOLERANCE`  | 0.03                            | Max price deviation before skipping  |
| `POLYMARKET_COPY_SELLS`       | true                            | Also replicate sell trades           |
| `POLYMARKET_TRADE_COALESCE_WINDOW_SECONDS` | 5                  | Time window to merge multi-fill trades from same order |
| `POLYMARKET_DRY_RUN`          | true                            | Log only, no real orders             |
| `POLYMARKET_POLL_BATCH_SIZE`  | 15                              | Wallets per concurrent batch in poll cycle |
| `POLYMARKET_POLL_BATCH_DELAY_MS` | 500                          | Delay between batches in milliseconds |
| `POLYMARKET_PENDING_ORDER_TTL_MINUTES` | 10                     | Auto-cancel unfilled orders after N minutes |
| `POLYMARKET_INACTIVE_WALLET_DAYS` | 3                              | Days without trades before wallet is considered inactive |
| `POLYMARKET_INACTIVE_POLL_INTERVAL_SECONDS` | 3600                | How often to poll inactive wallets (default 1 hour) |
| `POLYMARKET_AUTO_PAUSE_GRACE_PERIOD_TRADES` | 10               | Min closed trades before rules 3-6 apply (grace period) |
| `POLYMARKET_AUTO_PAUSE_MAX_UNREALIZED_LOSS` | -50              | Unrealized P&L below which wallet is paused (Rule 1, always applies) |
| `POLYMARKET_AUTO_PAUSE_MIN_EXPOSURE` | 100                     | Min invested $ before exposure-loss rule applies (Rule 2, always applies) |
| `POLYMARKET_AUTO_PAUSE_MAX_EXPOSURE_LOSS_RATIO` | 0.20         | Unrealized loss / invested ratio that triggers pause (Rule 2) |
| `POLYMARKET_AUTO_PAUSE_BAD_RECORD_MIN_TRADES` | 15             | Min closed trades before track-record rule applies (Rule 3) |
| `POLYMARKET_AUTO_PAUSE_BAD_RECORD_MAX_WIN_RATE` | 30           | Win rate % below which wallet may be paused (Rule 3) |
| `POLYMARKET_AUTO_PAUSE_BAD_RECORD_MAX_LOSS` | -25              | Combined P&L below which wallet may be paused (Rule 3) |
| `POLYMARKET_AUTO_PAUSE_ZERO_WIN_MIN_TRADES` | 8                | Min closed trades with 0 wins to trigger pause (Rule 4) |
| `POLYMARKET_AUTO_PAUSE_ROLLING_EXPECTANCY_TRADES` | 40         | Rolling window size for expectancy check (Rule 5) |
| `POLYMARKET_AUTO_PAUSE_MIN_PROFIT_FACTOR` | 0.7                | Profit factor below which wallet is paused (Rule 6) |
| `POLYMARKET_AUTO_PAUSE_PROFIT_FACTOR_MIN_TRADES` | 25          | Min trades before profit factor rule applies (Rule 6) |
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
| Gamma API              | `https://gamma-api.polymarket.com`   | Market resolution status, market metadata (slug, question, image, outcome) |

## Execution Flow

### Normal Operation (bot running)

1. **Every 30s** - `bot:poll`: Fetches trades from active (non-paused) tracked wallets in rate-limited batches (default 15 per batch, 500ms delay, with 429 retry). Detects new ones via per-wallet timestamp watermarks (`last_trade_ts`), applies copy filters, places orders. Inactive wallets (no trades in 3+ days) are only polled hourly to reduce load. `withoutOverlapping` mutex prevents stacking. Matched orders update positions immediately; live/delayed orders create PendingOrder records
2. **Every 30s** - `bot:update-prices`: Fetches midpoints for all open positions concurrently, updates DB. Falls back to resolution check if midpoint fails. Backfills market metadata (slug, question, image, outcome) for up to 5 positions per cycle that are missing any field (via Gamma API `getMarketMetadata()`, cached 24h). Also caches Polymarket USDC balance in BotMeta (skipped in dry-run)
3. **Every 30s** - `bot:check-orders`: Polls CLOB API for pending order status. Filled orders update positions with actual fill price. Orders older than 10min (configurable) are auto-cancelled
4. **Every 5min** - `bot:check-resolved`: Closes positions in resolved markets (WON=$1, LOST=$0, VOIDED=partial)
5. **Every 5min** - `bot:check-wallets`: Evaluates active wallets using `Setting::get()` for all thresholds (configurable from Settings UI). **Grace period**: wallets with fewer than N closed trades (default 10) skip rules 3-6; rules 1-2 always apply. Auto-pauses if ANY rule triggers: (1) unrealized P&L < -$50, (2) invested > $100 and unrealized loss > 20% of invested, (3) 15+ closed trades with <30% win rate and combined P&L < -$25, (4) 8+ closed trades with zero wins, (5) 40+ trades with negative rolling expectancy, (6) 25+ trades with profit factor < 0.7
6. **Hourly** - `bot:discover-wallets`: Fetches Polymarket leaderboard, auto-adds up to 3 top traders meeting PNL/volume thresholds (skips already-tracked)
7. **Every 10s** - Dashboard auto-refreshes from DB (no API calls)

### On Startup

1. Newly added wallets have `last_trade_ts = null`. First poll for each wallet sets the watermark to the highest trade timestamp without copying — prevents copying historical trades
2. Subsequent polls detect only trades with timestamps above the per-wallet watermark
3. Reconciliation checks if tracked traders sold while bot was offline

### Trade Copy Pipeline

1. Check global pause — skip all if bot is paused
2. Detect new trades from active (non-paused) tracked wallets
3. **Coalesce multi-fill trades** — group by (wallet + asset + side) within 5s window, merge into single trade with VWAP and summed size
4. Filter: skip sells if disabled, skip below min price ($0.05)
5. Calculate size: dynamic amount based on wallet score and available balance (hybrid % with min/max caps), all held shares for sells
6. Price sanity: skip if midpoint deviates > 3 cents from trade price
7. Trading balance limit: skip if trade amount exceeds available capital (limit - invested + realized P&L)
8. Per-market exposure cap: skip if would exceed $10 per market
9. **Per-wallet exposure cap**: skip if total invested from this wallet would exceed $20
10. Place order via CLOB API (or log in dry-run mode)
11. If matched/dry-run: extract actual fill price, update position immediately with weighted-average buy price, fetch market_slug
12. If live/delayed: create PendingOrder record, defer position update. `bot:check-orders` will poll for fill and apply update or auto-cancel after 10min TTL

## Key Design Decisions

- **Dashboard reads from DB only** - Prices are cached in the `positions` table by `bot:update-prices`. All API endpoints make zero external API calls, keeping response times ~20ms. Dashboard stats use SQL `SUM`/`COUNT` aggregates instead of loading rows into PHP.
- **Dashboard filtering** - Stats cards, positions table, and trade history table can be filtered by tracked wallets (multi-select) and time period (1D/1W/1M/All). Wallet filter applies `whereIn('copied_from_wallet', ...)` to positions, trade_history, and `/api/data` aggregates. Period filter applies `where('closed_at', '>=', cutoff)` to realized stats and trade history; unrealized stats show current state regardless of period (positions are live). BalanceBar always shows unfiltered data — Dashboard.vue maintains separate `data` (unfiltered) and `statsData` (filtered) refs, fetched in parallel via `Promise.all`. When no filters active, `/api/data` uses the fast PnlSummary singleton; when filtered, it computes from raw `trade_history`. DataTable `extraParams` watcher uses `JSON.stringify` comparison to avoid duplicate fetches on re-render.
- **Server-side pagination** - All tables use SQL-level sorting (ORDER BY) and pagination (LIMIT/OFFSET). Positions and trades use Eloquent `paginate()`. Wallet report uses a single query with LEFT JOIN subqueries. Only 10 rows per request instead of full datasets. WalletScoring is computed only for the current page's wallets. Columns not backed by SQL (e.g. composite_score) are marked `sortable: false` in the frontend.
- **Lazy tab loading** - Dashboard uses `v-if` (not `v-show`) for tab content — inactive tabs unmount completely, stopping their API polling. Each tab has its own `refreshTrigger` counter; only the active tab gets bumped on the 10s interval. On tab switch, the new tab's trigger is bumped so it fetches fresh data immediately. Result: on non-Dashboard tabs, only the lightweight `/api/data` polls (stats + balance); no positions/trades/wallets queries fire.
- **Trading balance limit** - User-configurable limit stored in `BotMeta`. Uses Polymarket-style accounting: `Available = Limit - Total Invested + Realized P&L`. Profits expand available capital, losses shrink it. BUY trades are skipped when trade amount exceeds available. In dry-run mode, the limit is freely editable. In live mode, it cannot exceed the real Polymarket balance.
- **Dynamic position sizing** - Hybrid % of available balance with min/max caps, scaled by wallet composite score. Three tiers: score 70+ (0.5%, max $10), score 50-69 (0.3%, max $5), score 30-49 (0.15%, max $3). All tiers have a $1 floor. Wallets with no score or score <30 use the fixed fallback amount ($2). As available balance grows, trade sizes grow proportionally; as it shrinks, sizes shrink automatically. When `fixed_amount_override` is set (via Settings tab), all dynamic sizing is bypassed and every BUY trade uses that fixed USDC amount.
- **Runtime settings** - Trading parameters (sizing, limits, behavior, polling) are configurable at runtime via the Settings tab without restarting containers. Settings stored in `BotMeta` with `setting:` key prefix. `Setting::get($key)` checks DB override first, falls back to `config('polymarket.{key}')` (which reads `.env`). Clearing a DB override reverts to the env default. All services read settings at call-time (not constructor-time), so changes take effect on the next poll cycle.
- **Dry-run by default** - `POLYMARKET_DRY_RUN=true` prevents accidental real trades. Must explicitly set to `false` for live trading.
- **Per-wallet timestamp watermarks** - Each wallet stores `last_trade_ts` (the highest trade timestamp seen). Only trades strictly newer than the watermark are processed. Newly added wallets seed on first poll (watermark set, no trades copied). This replaces the previous `SeenTrade` hash-based dedup which was vulnerable to pruning (50k limit) causing old trades from inactive wallets to be re-detected and copied into already-resolved markets.
- **Weighted-average buy price** - When buying the same asset multiple times, the buy price is the weighted average across all buys. Uses the actual fill price from the CLOB API response, not the tracked trader's execution price.
- **Multi-fill trade coalescing** - Large orders on Polymarket fill against multiple resting orders at different price levels, creating many trade records for a single logical trade. `BotPoll::coalesceTrades()` groups detected trades by (wallet + asset_id + side) within a configurable time window (default 5s via `POLYMARKET_TRADE_COALESCE_WINDOW_SECONDS`), and merges each group into a single `DetectedTrade` with volume-weighted average price and summed size. Uses an "anchor" clustering approach (compare to cluster start, not previous trade) to prevent drift. Coalescing only affects what gets passed to TradeCopier — individual fills are filtered by the per-wallet timestamp watermark.
- **Per-wallet exposure cap** - In addition to the per-market cap ($10), a per-wallet cap ($20 via `POLYMARKET_MAX_WALLET_EXPOSURE_USDC`) limits total invested across all positions from a single tracked wallet. Prevents one aggressive trader from dominating the portfolio by making many trades across different markets. Includes pending BUY orders per wallet to avoid overcommitting.
- **Actual fill price tracking** - `placeOrder()` returns a structured result with the actual execution price. For `matched` orders (immediately filled), the price is derived from the CLOB response's `makingAmount/takingAmount` (BUY: USDC spent ÷ shares received; SELL: USDC received ÷ shares sold). For `live` or `delayed` orders (sitting on orderbook, not yet filled), position updates are deferred via `PendingOrder` — the actual fill price is captured when `bot:check-orders` detects the order has been matched.
- **Pending order lifecycle** - Orders that don't fill immediately (CLOB status `live` or `delayed`) are tracked in the `pending_orders` table. `bot:check-orders` runs every 30s: polls `GET /order/{id}` for status, applies position updates on fill (with actual fill price), and auto-cancels orders older than 10min (configurable via `POLYMARKET_PENDING_ORDER_TTL_MINUTES`). Trading balance and exposure cap checks include pending BUY order amounts to prevent overcommitting capital. Positions are never updated prematurely — only confirmed fills modify shares/exposure/buy_price. Resolved pending orders are pruned after 7 days.
- **Market metadata** - Each position and trade history record stores `market_slug`, `market_question`, `market_image`, and `outcome` (all nullable) fetched from the Gamma API via `getMarketMetadata()` on first BUY. Metadata is cached 24h in Laravel cache and persisted in DB so dashboard API endpoints make zero external calls. `bot:update-prices` backfills metadata for up to 5 positions per cycle that are missing any field. All metadata is copied from position to trade_history on close. For grouped markets (multiple options under one event), the question shows the event title and the outcome shows the specific option (e.g. "280-299" instead of generic "Yes"); "No" side outcomes are prefixed (e.g. "No 280-299"). For standalone Yes/No markets, uses market question directly. The ActivityTable renders rich market info: thumbnail image, clickable question text linked to `polymarket.com/event/{slug}`, colored outcome badge with price, and share count — with graceful fallback to `shortId(asset_id)` when metadata is missing.
- **Market resolution handling** - Resolved markets (no orderbook) are detected and closed with correct payout ($1 for winners, $0 for losers, partial for voided).
- **Wallet pause/stop** - Wallets can be manually paused from the UI or auto-paused by `bot:check-wallets` based on configurable performance thresholds (all editable from Settings > Auto-Pause Rules). A grace period (default 10 trades) protects new wallets — rules 3-6 (statistical rules) are skipped until enough closed trades exist, while rules 1-2 (unrealized loss protection) always apply. Defaults are relaxed for small position sizes: higher min trade counts, lower win rate thresholds, and lower profit factor minimums. `CheckWallets` reads all thresholds via `Setting::get()` so changes from the Settings UI take effect on the next 5-minute cycle. Paused wallets stop being polled for new trades but existing positions remain open and manageable. Auto-paused wallets show a distinct red "Paused (Auto)" badge vs orange "Paused" for manual. Resuming is always manual via UI.
- **Wallet discovery** - Top traders auto-discovered hourly from the Polymarket leaderboard API (Data API `/v1/leaderboard`). Filters by PNL and volume thresholds. Max 3 auto-adds per run to prevent flooding. Manual discovery via the Discover tab allows scanning with custom time period/category and one-click adding.
- **Global pause & close all** - "Pause Bot" button in the header sets `BotMeta::global_paused`. Both `TradeTracker::poll()` and `TradeCopier::copy()` check this flag and skip all work when paused. Pulsing red "BOT PAUSED" badge shown in header. "Close All" button sells every open position at current midpoint with a confirmation dialog. Both buttons are always visible regardless of active tab.
- **Rate-limited polling** - Polymarket's Data API returns HTTP 429 when too many concurrent requests are fired. `TradeTracker` splits wallets into configurable batches (default 15 via `POLYMARKET_POLL_BATCH_SIZE`), fires each batch concurrently via `Http::pool`, then waits a configurable delay (default 500ms via `POLYMARKET_POLL_BATCH_DELAY_MS`) before the next batch. Wallets that receive a 429 are collected and retried once (with 2× delay) after all primary batches complete. `Http::pool` can return `ConnectionException` objects (not `Response`) on timeout — these are handled gracefully as null. A summary log (`poll_batch_summary`) reports total/failed/retried counts per cycle.
- **Tiered polling** - Wallets are partitioned into active (last trade within 3 days, configurable via `POLYMARKET_INACTIVE_WALLET_DAYS`) and inactive (older). Active wallets are polled every 30s; inactive wallets only once per hour (configurable via `POLYMARKET_INACTIVE_POLL_INTERVAL_SECONDS`). The `last_inactive_poll_ts` in BotMeta tracks when inactive wallets were last included. If an inactive wallet trades again, its `last_trade_ts` updates and it automatically returns to the active tier. Newly added wallets (null watermark) are always treated as active. This reduces per-cycle API load proportionally to the number of dormant wallets.
- **Performance optimizations** - Database indexes on `copied_from_wallet` (positions + trade_history), `opened_at`/`closed_at` (trade_history), and `market_status` (positions). All aggregation uses SQL `GROUP BY` / `SUM` / `COUNT` instead of loading full tables into PHP. `TradeTracker` uses per-wallet timestamp watermarks for O(1) dedup (no hash table lookups or DB queries per trade).

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
    ActivityController.php   # GET /api/activity - unified chronological activity feed
    BalanceController.php    # PUT /api/balance - trading balance limit
    DashboardController.php  # Dashboard page + /api/data (summary stats only)
    DiscoverController.php   # GET/POST /api/discover - leaderboard discovery
    GlobalPauseController.php # POST /api/global-pause - toggle bot pause
    PositionController.php   # GET /api/positions (paginated) + POST /api/close + POST /api/close-all
    WalletReportController.php # GET /api/wallet-report (paginated)
    TradeHistoryController.php # GET /api/trades (paginated)
    SettingsController.php   # GET/PUT/DELETE /api/settings - runtime config + POST /api/reset-data
    WalletController.php     # CRUD /api/wallets + pause/resume + bulk delete/pause
  Models/
    BotMeta.php              # Key-value metadata store
    PendingOrder.php         # Orders awaiting fill on the CLOB orderbook
    PnlSummary.php           # Aggregate P&L singleton
    Position.php             # Open positions
    SeenTrade.php            # Legacy deduplication table (replaced by per-wallet timestamp watermarks)
    TrackedWallet.php        # Tracked wallet addresses (with pause state + last_trade_ts watermark)
    TradeHistory.php         # Closed trade records
  Services/
    LeaderboardDiscovery.php # Leaderboard API + wallet discovery
    WalletScoring.php        # Composite score + advanced metrics per wallet
    PolymarketClient.php     # CLOB/Gamma API integration
    Setting.php              # Runtime-configurable settings (DB override + env fallback)
    TradeCopier.php          # Trade replication + position management
    TradeTracker.php         # Wallet polling + trade detection
config/
  polymarket.php             # All trading configuration
database/migrations/         # 14 migration files (includes performance indexes, market_slug, market_metadata, pending_orders, last_trade_ts)
resources/js/
  Pages/Dashboard.vue        # Main page (tabs: Dashboard, Wallets, Report, Discover, Settings)
  Components/
    BalanceBar.vue           # Balance management (Polymarket + trading limit)
    DataTable.vue            # Generic server-side paginated/sortable table with slots
    Pagination.vue           # Shared pagination + per-page selector (10/25/50/100)
    StatsCards.vue            # P&L stat cards
    StatsFilter.vue          # Wallet multi-select + time period filter (1D/1W/1M/All)
    ActivityTable.vue        # Unified positions/trades with Positions/Activity sub-tabs (Polymarket-style)
    PositionsTable.vue       # Legacy: open positions (uses DataTable)
    TradeHistoryTable.vue    # Legacy: closed trades (uses DataTable)
    Settings.vue             # Bot settings UI (grouped, with toggle/reset per field)
    WalletDiscovery.vue      # Leaderboard discovery + add wallets
    WalletsManager.vue       # Wallet CRUD UI (checkbox select, bulk pause/resume/delete, client-side pagination)
    WalletReport.vue         # Per-wallet performance report (uses DataTable)
  utils/
    formatters.js            # Shared formatting: fmtUsd, pnlClass, fmtDate, timeAgo, shortId, traderLabel, traderUrl, marketUrl
routes/
  api.php                    # API endpoints
  console.php                # Scheduled tasks
  web.php                    # Web routes
docker/
  wait-for-db.php            # DB readiness check script
Dockerfile                   # PHP 8.4 + Node 20
docker-compose.yml           # app + scheduler + db
```
