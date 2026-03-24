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
| `bot:poll`           | Every 30s   | Fetch trades from tracked wallets, copy them  |
| `bot:update-prices`  | Every 30s   | Update current prices in DB for the dashboard |
| `bot:check-resolved` | Every 5 min | Close positions in resolved markets           |

### Services (`app/Services/`)

- **PolymarketClient** - CLOB API wrapper. Handles order placement (EIP-712 signing), midpoint prices, balance checks, and market resolution lookups via Gamma API. Caches prices (15s success, 5min failure) and resolution status (1hr resolved, 5min active).
- **TradeCopier** - Core trade replication logic. Applies filters (price tolerance, exposure cap, sell filter), manages positions with weighted-average buy prices, records P&L, handles startup reconciliation and manual position closes.
- **TradeTracker** - Polls Polymarket Data API for new trades. Seeds existing trades on first run to avoid copying historical trades. Prunes seen trades at 50k entries.

### Controllers (`app/Http/Controllers/`)

- **DashboardController** - `GET /` renders the Vue dashboard. `GET /api/data` returns all dashboard data from DB (no external API calls, instant response).
- **WalletController** - CRUD for tracked wallets: `POST /api/wallets` (add), `PUT /api/wallets` (update name/slug), `DELETE /api/wallets` (remove).
- **PositionController** - `POST /api/close` manually closes a position at current midpoint.

### Models (`app/Models/`)

| Model          | Purpose                                                        |
|----------------|----------------------------------------------------------------|
| `Position`     | Open positions (asset_id, shares, buy_price, current_price)    |
| `TrackedWallet`| Wallet addresses being tracked (address, name, profile_slug)   |
| `TradeHistory` | Closed trade records (buy/sell price, shares, pnl, timestamps) |
| `PnlSummary`   | Singleton row with aggregate P&L stats                         |
| `BotMeta`      | Key-value store for bot metadata (last_running_ts)             |
| `SeenTrade`    | Transaction hashes of already-processed trades                 |

### Vue Frontend (`resources/js/`)

- **Dashboard.vue** - Main page with two tabs (Dashboard, Wallets). Auto-refreshes `/api/data` every 10 seconds.
- **StatsCards.vue** - Six stat cards: Combined P&L, Unrealized P&L, Realized P&L, Win Rate, Open Positions, Total Invested.
- **PositionsTable.vue** - Paginated, sortable table of open positions with Close button and trader profile links.
- **TradeHistoryTable.vue** - Paginated, sortable table of closed trades with P&L and trader profile links.
- **WalletsManager.vue** - Add/edit/remove tracked wallets with inline editing for name and profile slug.

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

### External APIs

| API                    | Base URL                             | Used For                                     |
|------------------------|--------------------------------------|----------------------------------------------|
| CLOB API               | `https://clob.polymarket.com`        | Order placement, midpoints, balance, markets  |
| Data API               | `https://data-api.polymarket.com`    | Fetching trades by wallet                     |
| Gamma API              | `https://gamma-api.polymarket.com`   | Market resolution status lookup               |

## Execution Flow

### Normal Operation (bot running)

1. **Every 30s** - `bot:poll`: Fetches trades from all tracked wallets, detects new ones via `SeenTrade` deduplication, applies copy filters, places orders
2. **Every 30s** - `bot:update-prices`: Fetches midpoints for all open positions concurrently, updates DB. Falls back to resolution check if midpoint fails
3. **Every 5min** - `bot:check-resolved`: Closes positions in resolved markets (WON=$1, LOST=$0, VOIDED=partial)
4. **Every 10s** - Dashboard auto-refreshes from DB (no API calls)

### On Startup

1. First `bot:poll` run seeds all existing trade IDs as "seen" (no copies)
2. Subsequent runs detect only new trades
3. Reconciliation checks if tracked traders sold while bot was offline

### Trade Copy Pipeline

1. Detect new trade from tracked wallet
2. Filter: skip sells if disabled, skip zero price
3. Calculate size: `$2 / trade_price` for buys, all held shares for sells
4. Price sanity: skip if midpoint deviates > 3 cents from trade price
5. Exposure cap: skip if would exceed $100 per market
6. Place order via CLOB API (or log in dry-run mode)
7. Update position with weighted-average buy price, save to DB

## Key Design Decisions

- **Dashboard reads from DB only** - Prices are cached in the `positions` table by `bot:update-prices`. The `/api/data` endpoint makes zero external API calls, keeping response times ~20ms.
- **Dry-run by default** - `POLYMARKET_DRY_RUN=true` prevents accidental real trades. Must explicitly set to `false` for live trading.
- **First-run seeding** - On first poll, all existing trades are marked as "seen" to prevent copying historical trades.
- **Weighted-average buy price** - When buying the same asset multiple times, the buy price is the weighted average across all buys.
- **Market resolution handling** - Resolved markets (no orderbook) are detected and closed with correct payout ($1 for winners, $0 for losers, partial for voided).

## File Structure

```
app/
  Console/Commands/
    BotPoll.php              # bot:poll - one poll cycle
    BotReconcile.php         # Startup reconciliation (called from BotPoll)
    CheckResolved.php        # bot:check-resolved - close resolved positions
    MigrateFromPython.php    # bot:migrate-from-python - one-time import
    UpdatePrices.php         # bot:update-prices - cache midpoints in DB
  DTOs/
    DetectedTrade.php        # Immutable trade data object
  Http/Controllers/
    DashboardController.php  # Dashboard page + /api/data
    PositionController.php   # POST /api/close
    WalletController.php     # CRUD /api/wallets
  Models/
    BotMeta.php              # Key-value metadata store
    PnlSummary.php           # Aggregate P&L singleton
    Position.php             # Open positions
    SeenTrade.php            # Deduplication of processed trades
    TrackedWallet.php        # Tracked wallet addresses
    TradeHistory.php         # Closed trade records
  Services/
    PolymarketClient.php     # CLOB/Gamma API integration
    TradeCopier.php          # Trade replication + position management
    TradeTracker.php         # Wallet polling + trade detection
config/
  polymarket.php             # All trading configuration
database/migrations/         # 8 migration files
resources/js/
  Pages/Dashboard.vue        # Main page (tabs: Dashboard, Wallets)
  Components/
    StatsCards.vue            # P&L stat cards
    PositionsTable.vue       # Open positions table
    TradeHistoryTable.vue    # Closed trades table
    WalletsManager.vue       # Wallet CRUD UI
routes/
  api.php                    # API endpoints
  console.php                # Scheduled tasks
  web.php                    # Web routes
docker/
  wait-for-db.php            # DB readiness check script
Dockerfile                   # PHP 8.4 + Node 20
docker-compose.yml           # app + scheduler + db
```
