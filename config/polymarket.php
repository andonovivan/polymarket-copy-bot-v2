<?php

return [
    'dashboard_password' => env('DASHBOARD_PASSWORD', ''),
    'clob_api_url' => env('POLYMARKET_CLOB_API_URL', 'https://clob.polymarket.com'),
    'data_api_url' => env('POLYMARKET_DATA_API_URL', 'https://data-api.polymarket.com'),
    'chain_id' => (int) env('POLYMARKET_CHAIN_ID', 137),
    'private_key' => env('POLYMARKET_PRIVATE_KEY', ''),
    'api_key' => env('POLYMARKET_API_KEY', ''),
    'api_secret' => env('POLYMARKET_API_SECRET', ''),
    'api_passphrase' => env('POLYMARKET_API_PASSPHRASE', ''),
    'fixed_amount_usdc' => (float) env('POLYMARKET_FIXED_AMOUNT_USDC', 2.0),
    'max_position_usdc' => (float) env('POLYMARKET_MAX_POSITION_USDC', 10.0),
    'max_wallet_exposure_usdc' => (float) env('POLYMARKET_MAX_WALLET_EXPOSURE_USDC', 20.0),
    'max_global_market_usdc' => (float) env('POLYMARKET_MAX_GLOBAL_MARKET_USDC', 30.0),
    'price_tolerance' => (float) env('POLYMARKET_PRICE_TOLERANCE', 0.03),
    'min_trade_price' => (float) env('POLYMARKET_MIN_TRADE_PRICE', 0.05),
    'max_position_high_price_usdc' => (float) env('POLYMARKET_MAX_POSITION_HIGH_PRICE_USDC', 5.0),
    'high_price_threshold' => (float) env('POLYMARKET_HIGH_PRICE_THRESHOLD', 0.80),
    'max_trade_age_seconds' => (int) env('POLYMARKET_MAX_TRADE_AGE_SECONDS', 30),
    'momentum_filter' => env('POLYMARKET_MOMENTUM_FILTER', true),
    'copy_sells' => env('POLYMARKET_COPY_SELLS', true),
    'poll_interval_seconds' => (int) env('POLYMARKET_POLL_INTERVAL_SECONDS', 30),
    'poll_batch_size' => (int) env('POLYMARKET_POLL_BATCH_SIZE', 15),
    'poll_batch_delay_ms' => (int) env('POLYMARKET_POLL_BATCH_DELAY_MS', 500),
    'trade_coalesce_window_seconds' => (int) env('POLYMARKET_TRADE_COALESCE_WINDOW_SECONDS', 5),
    'dry_run' => env('POLYMARKET_DRY_RUN', true),
    'inactive_wallet_days' => (int) env('POLYMARKET_INACTIVE_WALLET_DAYS', 3),
    'inactive_poll_interval_seconds' => (int) env('POLYMARKET_INACTIVE_POLL_INTERVAL_SECONDS', 3600),
    'pending_order_ttl_minutes' => (int) env('POLYMARKET_PENDING_ORDER_TTL_MINUTES', 10),

    // Take-profit / Stop-loss — auto-close positions at thresholds.
    'max_position_age_hours' => (int) env('POLYMARKET_MAX_POSITION_AGE_HOURS', 72),
    'max_market_duration_days' => (int) env('POLYMARKET_MAX_MARKET_DURATION_DAYS', 30),
    'enable_tp_sl' => env('POLYMARKET_ENABLE_TP_SL', true),
    'tp_percentage' => (float) env('POLYMARKET_TP_PERCENTAGE', 20),
    'sl_percentage' => (float) env('POLYMARKET_SL_PERCENTAGE', 15),

    // Auto-pause thresholds — wallet is paused if ANY rule triggers.
    'auto_pause_enabled' => env('POLYMARKET_AUTO_PAUSE_ENABLED', true),
    // Grace period: skip ALL rules until wallet has at least N closed trades.
    'auto_pause_grace_period_trades' => (int) env('POLYMARKET_AUTO_PAUSE_GRACE_PERIOD_TRADES', 10),
    // Rule 1: Deep unrealized loss (absolute).
    'auto_pause_max_unrealized_loss' => (float) env('POLYMARKET_AUTO_PAUSE_MAX_UNREALIZED_LOSS', -50),
    // Rule 2: High exposure + losing (unrealized loss > ratio of invested, when invested > min).
    'auto_pause_min_exposure' => (float) env('POLYMARKET_AUTO_PAUSE_MIN_EXPOSURE', 100),
    'auto_pause_max_exposure_loss_ratio' => (float) env('POLYMARKET_AUTO_PAUSE_MAX_EXPOSURE_LOSS_RATIO', 0.20),
    // Rule 3: Bad closed track record (min trades + low win rate + negative combined P&L).
    'auto_pause_bad_record_min_trades' => (int) env('POLYMARKET_AUTO_PAUSE_BAD_RECORD_MIN_TRADES', 15),
    'auto_pause_bad_record_max_win_rate' => (float) env('POLYMARKET_AUTO_PAUSE_BAD_RECORD_MAX_WIN_RATE', 30),
    'auto_pause_bad_record_max_loss' => (float) env('POLYMARKET_AUTO_PAUSE_BAD_RECORD_MAX_LOSS', -25),
    // Rule 4: Small sample but zero wins.
    'auto_pause_zero_win_min_trades' => (int) env('POLYMARKET_AUTO_PAUSE_ZERO_WIN_MIN_TRADES', 8),
    // Rule 5: Negative rolling expectancy (avg P&L over last N trades).
    'auto_pause_rolling_expectancy_trades' => (int) env('POLYMARKET_AUTO_PAUSE_ROLLING_EXPECTANCY_TRADES', 40),
    // Rule 6: Low profit factor (gross profit / gross loss < threshold after N trades).
    'auto_pause_min_profit_factor' => (float) env('POLYMARKET_AUTO_PAUSE_MIN_PROFIT_FACTOR', 0.7),
    'auto_pause_profit_factor_min_trades' => (int) env('POLYMARKET_AUTO_PAUSE_PROFIT_FACTOR_MIN_TRADES', 25),

    // Composite wallet score — minimum trades to compute.
    'score_min_trades' => (int) env('POLYMARKET_SCORE_MIN_TRADES', 5),

    // Dynamic position sizing — hybrid % of available balance with min/max caps.
    // Tiers: high (score 70+), mid (score 50-69), low (score 30-49), below 30 = auto-paused.
    'sizing_high_pct' => (float) env('POLYMARKET_SIZING_HIGH_PCT', 0.50),
    'sizing_high_max' => (float) env('POLYMARKET_SIZING_HIGH_MAX', 10.0),
    'sizing_mid_pct' => (float) env('POLYMARKET_SIZING_MID_PCT', 0.30),
    'sizing_mid_max' => (float) env('POLYMARKET_SIZING_MID_MAX', 5.0),
    'sizing_low_pct' => (float) env('POLYMARKET_SIZING_LOW_PCT', 0.15),
    'sizing_low_max' => (float) env('POLYMARKET_SIZING_LOW_MAX', 3.0),
    'sizing_min' => (float) env('POLYMARKET_SIZING_MIN', 1.0),

    // Kelly Criterion sizing — mathematically optimal bet sizing based on wallet edge.
    'use_kelly_sizing' => env('POLYMARKET_USE_KELLY_SIZING', false),
    'kelly_fraction_multiplier' => (float) env('POLYMARKET_KELLY_FRACTION_MULTIPLIER', 0.5),
    'kelly_min_trades' => (int) env('POLYMARKET_KELLY_MIN_TRADES', 20),

    // Wallet discovery — auto-discover top traders from the leaderboard.
    'discover_min_pnl' => (float) env('POLYMARKET_DISCOVER_MIN_PNL', 500),
    'discover_min_volume' => (float) env('POLYMARKET_DISCOVER_MIN_VOLUME', 10000),
    'discover_time_period' => env('POLYMARKET_DISCOVER_TIME_PERIOD', 'WEEK'),
    'discover_category' => env('POLYMARKET_DISCOVER_CATEGORY', 'OVERALL'),
    'discover_limit' => (int) env('POLYMARKET_DISCOVER_LIMIT', 20),
    'discover_max_auto_add' => (int) env('POLYMARKET_DISCOVER_MAX_AUTO_ADD', 3),

    // Market category filters — set to false to skip new BUY trades in that category.
    'category_crypto' => env('POLYMARKET_CATEGORY_CRYPTO', true),
    'category_politics' => env('POLYMARKET_CATEGORY_POLITICS', true),
    'category_sports' => env('POLYMARKET_CATEGORY_SPORTS', true),
    'category_pop_culture' => env('POLYMARKET_CATEGORY_POP_CULTURE', true),
    'category_business' => env('POLYMARKET_CATEGORY_BUSINESS', true),
    'category_science' => env('POLYMARKET_CATEGORY_SCIENCE', true),
    'category_other' => env('POLYMARKET_CATEGORY_OTHER', true),

    // Resolution sniping — buy high-probability outcomes on markets about to resolve.
    'snipe_enabled' => env('POLYMARKET_SNIPE_ENABLED', true),
    'snipe_auto_trade' => env('POLYMARKET_SNIPE_AUTO_TRADE', false),
    'snipe_min_probability' => (float) env('POLYMARKET_SNIPE_MIN_PROBABILITY', 0.90),
    'snipe_max_hours' => (int) env('POLYMARKET_SNIPE_MAX_HOURS', 48),
    'snipe_trade_amount' => (float) env('POLYMARKET_SNIPE_TRADE_AMOUNT', 5.0),

    // Mapping of category keys to Polymarket event tag slugs.
    'market_category_tags' => [
        'crypto' => ['crypto', 'airdrops'],
        'politics' => ['politics', 'geopolitics', 'elections', 'global-elections', 'us-presidential-election', 'world-elections', 'midterms', 'primaries'],
        'sports' => ['sports', 'nba', 'nfl', 'mlb', 'soccer', 'football', 'baseball', 'basketball', 'tennis', 'hockey', 'mma', 'boxing', 'f1', 'golf'],
        'pop_culture' => ['pop-culture', 'entertainment', 'celebrity'],
        'business' => ['finance', 'business', 'economy', 'stocks', 'tech', 'ipos'],
        'science' => ['science', 'weather', 'climate'],
    ],
];
