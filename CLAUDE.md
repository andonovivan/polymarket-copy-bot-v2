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
| `bot:poll`           | Every 30s   | Fetch trades from active (non-paused) wallets, copy them |
| `bot:update-prices`  | Every 30s   | Update current prices in DB for the dashboard |
| `bot:check-resolved` | Every 5 min | Close positions in resolved markets           |
| `bot:check-wallets`  | Every 5 min | Auto-pause wallets with poor performance      |
| `bot:discover-wallets` | Every hour | Auto-discover top traders from Polymarket leaderboard |

### Services (`app/Services/`)

- **PolymarketClient** - CLOB API wrapper. Handles order placement (EIP-712 signing), midpoint prices, balance checks, and market resolution lookups via Gamma API. Caches prices (15s success, 5min failure) and resolution status (1hr resolved, 5min active).
- **TradeCopier** - Core trade replication logic. Applies filters (price tolerance, exposure cap, trading balance limit, sell filter), manages positions with weighted-average buy prices, records P&L, handles startup reconciliation and manual position closes.
- **TradeTracker** - Polls Polymarket Data API for new trades from active (non-paused) wallets. Seeds existing trades on first run to avoid copying historical trades. Prunes seen trades at 50k entries.
- **LeaderboardDiscovery** - Fetches top traders from Polymarket leaderboard API (`/v1/leaderboard`). Filters by min PNL/volume thresholds, skips already-tracked wallets. Used by both the scheduled `bot:discover-wallets` command and `GET/POST /api/discover` endpoints.

### Controllers (`app/Http/Controllers/`)

- **DashboardController** - `GET /` renders the Vue dashboard. `GET /api/data` returns summary stats, wallet report, and balance info from DB (no positions/trades arrays — those use separate paginated endpoints).
- **PositionController** - `GET /api/positions` paginated open positions (server-side sort/pagination). `POST /api/close` manually closes a position at current midpoint.
- **TradeHistoryController** - `GET /api/trades` paginated closed trades (server-side sort/pagination).
- **WalletController** - CRUD for tracked wallets: `POST /api/wallets` (add), `PUT /api/wallets` (update name/slug), `PATCH /api/wallets/pause` (pause/resume), `DELETE /api/wallets` (remove).
- **BalanceController** - `PUT /api/balance` updates the trading balance limit. Validates that limit cannot exceed real Polymarket balance when not in dry-run mode.
- **DiscoverController** - `GET /api/discover` returns leaderboard candidates (with already-tracked flags). `POST /api/discover` adds selected wallets by address array.

### Models (`app/Models/`)

| Model          | Purpose                                                        |
|----------------|----------------------------------------------------------------|
| `Position`     | Open positions (asset_id, shares, buy_price, current_price)    |
| `TrackedWallet`| Wallet addresses being tracked (address, name, profile_slug, is_paused, paused_at, pause_reason) |
| `TradeHistory` | Closed trade records (buy/sell price, shares, pnl, timestamps) |
| `PnlSummary`   | Singleton row with aggregate P&L stats                         |
| `BotMeta`      | Key-value store for bot metadata (last_running_ts, polymarket_balance, trading_balance) |
| `SeenTrade`    | Transaction hashes of already-processed trades                 |

### Vue Frontend (`resources/js/`)

- **Dashboard.vue** - Main page with four tabs (Dashboard, Wallets, Report, Discover). Auto-refreshes `/api/data` every 10 seconds. Passes `refreshTrigger` counter to table components so they re-fetch their current page.
- **BalanceBar.vue** - Balance management bar above stats cards. Shows Polymarket balance (read-only, N/A in dry-run), editable trading balance limit, available amount, and usage progress bar.
- **StatsCards.vue** - Six stat cards: Combined P&L, Unrealized P&L, Realized P&L, Win Rate, Open Positions, Total Invested.
- **PositionsTable.vue** - Server-side paginated, sortable table of open positions. Fetches from `GET /api/positions` with page/sort/order params. Includes Close button and trader profile links.
- **TradeHistoryTable.vue** - Server-side paginated, sortable table of closed trades. Fetches from `GET /api/trades` with page/sort/order params.
- **WalletsManager.vue** - Add/edit/remove tracked wallets with inline editing for name and profile slug. Pause/Resume toggle per wallet with badge showing manual vs auto-pause.
- **WalletReport.vue** - Per-wallet performance report with combined/realized/unrealized P&L, win rate, trade counts, performance rating badges, pause status (Active/Paused/Paused Auto) and Pause/Resume action buttons. Summary cards include paused wallet count.
- **WalletDiscovery.vue** - Leaderboard discovery UI. "Scan Leaderboard" button fetches candidates from `GET /api/discover` with configurable time period and category dropdowns. Shows ranked table with PNL, volume, Add/Tracked badges. "Add All" for bulk-add.

### Configuration (`config/polymarket.php`)

All trading parameters are configurable via `.env`:

| Env Variable                  | Default                         | Purpose                              |
|-------------------------------|---------------------------------|--------------------------------------|
| `POLYMARKET_PRIVATE_KEY`      | -                               | Wallet private key for signing       |
| `POLYMARKET_API_KEY`          | -                               | CLOB API key                         |
| `POLYMARKET_API_SECRET`       | -                               | CLOB API secret                      |
| `POLYMARKET_API_PASSPHRASE`   | -                               | CLOB API passphrase                  |
| `POLYMARKET_FIXED_AMOUNT_USDC`| 2                               | Fixed USDC per copied trade          |
| `POLYMARKET_MAX_POSITION_USDC`| 100                             | Max exposure per market              |
| `POLYMARKET_PRICE_TOLERANCE`  | 0.03                            | Max price deviation before skipping  |
| `POLYMARKET_COPY_SELLS`       | true                            | Also replicate sell trades           |
| `POLYMARKET_DRY_RUN`          | true                            | Log only, no real orders             |
| `POLYMARKET_AUTO_PAUSE_MAX_UNREALIZED_LOSS` | -50              | Unrealized P&L below which wallet is paused (Rule 1) |
| `POLYMARKET_AUTO_PAUSE_MIN_EXPOSURE` | 100                     | Min invested $ before exposure-loss rule applies (Rule 2) |
| `POLYMARKET_AUTO_PAUSE_MAX_EXPOSURE_LOSS_RATIO` | 0.20         | Unrealized loss / invested ratio that triggers pause (Rule 2) |
| `POLYMARKET_AUTO_PAUSE_BAD_RECORD_MIN_TRADES` | 5              | Min closed trades before track-record rule applies (Rule 3) |
| `POLYMARKET_AUTO_PAUSE_BAD_RECORD_MAX_WIN_RATE` | 40           | Win rate % below which wallet may be paused (Rule 3) |
| `POLYMARKET_AUTO_PAUSE_BAD_RECORD_MAX_LOSS` | -10              | Combined P&L below which wallet may be paused (Rule 3) |
| `POLYMARKET_AUTO_PAUSE_ZERO_WIN_MIN_TRADES` | 3                | Min closed trades with 0 wins to trigger pause (Rule 4) |
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
| Gamma API              | `https://gamma-api.polymarket.com`   | Market resolution status lookup               |

## Execution Flow

### Normal Operation (bot running)

1. **Every 30s** - `bot:poll`: Fetches trades from active (non-paused) tracked wallets, detects new ones via `SeenTrade` deduplication, applies copy filters, places orders
2. **Every 30s** - `bot:update-prices`: Fetches midpoints for all open positions concurrently, updates DB. Falls back to resolution check if midpoint fails. Also caches Polymarket USDC balance in BotMeta (skipped in dry-run)
3. **Every 5min** - `bot:check-resolved`: Closes positions in resolved markets (WON=$1, LOST=$0, VOIDED=partial)
4. **Every 5min** - `bot:check-wallets`: Evaluates active wallets and auto-pauses if ANY rule triggers: (1) unrealized P&L < -$50, (2) invested > $100 and unrealized loss > 20% of invested, (3) 5+ closed trades with <40% win rate and combined P&L < -$10, (4) 3+ closed trades with zero wins
5. **Hourly** - `bot:discover-wallets`: Fetches Polymarket leaderboard, auto-adds up to 3 top traders meeting PNL/volume thresholds (skips already-tracked)
6. **Every 10s** - Dashboard auto-refreshes from DB (no API calls)

### On Startup

1. First `bot:poll` run seeds all existing trade IDs as "seen" (no copies)
2. Subsequent runs detect only new trades
3. Reconciliation checks if tracked traders sold while bot was offline

### Trade Copy Pipeline

1. Detect new trade from active (non-paused) tracked wallet
2. Filter: skip sells if disabled, skip zero price
3. Calculate size: `$2 / trade_price` for buys, all held shares for sells
4. Price sanity: skip if midpoint deviates > 3 cents from trade price
5. Trading balance limit: skip if total invested + trade amount would exceed user-set limit
6. Exposure cap: skip if would exceed $100 per market
7. Place order via CLOB API (or log in dry-run mode)
8. Update position with weighted-average buy price, save to DB

## Key Design Decisions

- **Dashboard reads from DB only** - Prices are cached in the `positions` table by `bot:update-prices`. All API endpoints make zero external API calls, keeping response times ~20ms.
- **Server-side pagination** - Positions and trades tables use separate paginated API endpoints (`/api/positions`, `/api/trades`) with server-side sorting (SQL ORDER BY) and pagination (LIMIT/OFFSET). Only 10 rows per request instead of full datasets. The 10s auto-refresh triggers table re-fetches via a `refreshTrigger` counter prop.
- **Trading balance limit** - User-configurable limit stored in `BotMeta`. When total invested + trade amount exceeds limit, BUY trades are skipped. In dry-run mode, the limit is freely editable. In live mode, it cannot exceed the real Polymarket balance.
- **Dry-run by default** - `POLYMARKET_DRY_RUN=true` prevents accidental real trades. Must explicitly set to `false` for live trading.
- **First-run seeding** - On first poll, all existing trades are marked as "seen" to prevent copying historical trades.
- **Weighted-average buy price** - When buying the same asset multiple times, the buy price is the weighted average across all buys.
- **Market resolution handling** - Resolved markets (no orderbook) are detected and closed with correct payout ($1 for winners, $0 for losers, partial for voided).
- **Wallet pause/stop** - Wallets can be manually paused from the UI or auto-paused by `bot:check-wallets` based on configurable performance thresholds. Paused wallets stop being polled for new trades but existing positions remain open and manageable. Auto-paused wallets show a distinct red "Paused (Auto)" badge vs orange "Paused" for manual. Resuming is always manual via UI.
- **Wallet discovery** - Top traders auto-discovered hourly from the Polymarket leaderboard API (Data API `/v1/leaderboard`). Filters by PNL and volume thresholds. Max 3 auto-adds per run to prevent flooding. Manual discovery via the Discover tab allows scanning with custom time period/category and one-click adding.

## File Structure

```
app/
  Console/Commands/
    BotPoll.php              # bot:poll - one poll cycle
    BotReconcile.php         # Startup reconciliation (called from BotPoll)
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
    PositionController.php   # GET /api/positions (paginated) + POST /api/close
    TradeHistoryController.php # GET /api/trades (paginated)
    WalletController.php     # CRUD /api/wallets + PATCH /api/wallets/pause
  Models/
    BotMeta.php              # Key-value metadata store
    PnlSummary.php           # Aggregate P&L singleton
    Position.php             # Open positions
    SeenTrade.php            # Deduplication of processed trades
    TrackedWallet.php        # Tracked wallet addresses (with pause state)
    TradeHistory.php         # Closed trade records
  Services/
    LeaderboardDiscovery.php # Leaderboard API + wallet discovery
    PolymarketClient.php     # CLOB/Gamma API integration
    TradeCopier.php          # Trade replication + position management
    TradeTracker.php         # Wallet polling + trade detection
config/
  polymarket.php             # All trading configuration
database/migrations/         # 9 migration files
resources/js/
  Pages/Dashboard.vue        # Main page (tabs: Dashboard, Wallets, Report, Discover)
  Components/
    BalanceBar.vue           # Balance management (Polymarket + trading limit)
    StatsCards.vue            # P&L stat cards
    PositionsTable.vue       # Server-side paginated open positions
    TradeHistoryTable.vue    # Server-side paginated closed trades
    WalletDiscovery.vue      # Leaderboard discovery + add wallets
    WalletsManager.vue       # Wallet CRUD UI
    WalletReport.vue         # Per-wallet performance report
routes/
  api.php                    # API endpoints
  console.php                # Scheduled tasks
  web.php                    # Web routes
docker/
  wait-for-db.php            # DB readiness check script
Dockerfile                   # PHP 8.4 + Node 20
docker-compose.yml           # app + scheduler + db
```
