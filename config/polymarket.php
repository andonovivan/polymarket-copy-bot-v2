<?php

return [
    'clob_api_url' => env('POLYMARKET_CLOB_API_URL', 'https://clob.polymarket.com'),
    'data_api_url' => env('POLYMARKET_DATA_API_URL', 'https://data-api.polymarket.com'),
    'chain_id' => (int) env('POLYMARKET_CHAIN_ID', 137),
    'private_key' => env('POLYMARKET_PRIVATE_KEY', ''),
    'api_key' => env('POLYMARKET_API_KEY', ''),
    'api_secret' => env('POLYMARKET_API_SECRET', ''),
    'api_passphrase' => env('POLYMARKET_API_PASSPHRASE', ''),
    'fixed_amount_usdc' => (float) env('POLYMARKET_FIXED_AMOUNT_USDC', 2.0),
    'max_position_usdc' => (float) env('POLYMARKET_MAX_POSITION_USDC', 100.0),
    'price_tolerance' => (float) env('POLYMARKET_PRICE_TOLERANCE', 0.03),
    'min_trade_price' => (float) env('POLYMARKET_MIN_TRADE_PRICE', 0.05),
    'copy_sells' => env('POLYMARKET_COPY_SELLS', true),
    'poll_interval_seconds' => (int) env('POLYMARKET_POLL_INTERVAL_SECONDS', 30),
    'dry_run' => env('POLYMARKET_DRY_RUN', true),
    'pending_order_ttl_minutes' => (int) env('POLYMARKET_PENDING_ORDER_TTL_MINUTES', 10),

    // Auto-pause thresholds — wallet is paused if ANY rule triggers.
    // Rule 1: Deep unrealized loss (absolute).
    'auto_pause_max_unrealized_loss' => (float) env('POLYMARKET_AUTO_PAUSE_MAX_UNREALIZED_LOSS', -50),
    // Rule 2: High exposure + losing (unrealized loss > ratio of invested, when invested > min).
    'auto_pause_min_exposure' => (float) env('POLYMARKET_AUTO_PAUSE_MIN_EXPOSURE', 100),
    'auto_pause_max_exposure_loss_ratio' => (float) env('POLYMARKET_AUTO_PAUSE_MAX_EXPOSURE_LOSS_RATIO', 0.20),
    // Rule 3: Bad closed track record (min trades + low win rate + negative combined P&L).
    'auto_pause_bad_record_min_trades' => (int) env('POLYMARKET_AUTO_PAUSE_BAD_RECORD_MIN_TRADES', 5),
    'auto_pause_bad_record_max_win_rate' => (float) env('POLYMARKET_AUTO_PAUSE_BAD_RECORD_MAX_WIN_RATE', 40),
    'auto_pause_bad_record_max_loss' => (float) env('POLYMARKET_AUTO_PAUSE_BAD_RECORD_MAX_LOSS', -10),
    // Rule 4: Small sample but zero wins.
    'auto_pause_zero_win_min_trades' => (int) env('POLYMARKET_AUTO_PAUSE_ZERO_WIN_MIN_TRADES', 3),
    // Rule 5: Negative rolling expectancy (avg P&L over last N trades).
    'auto_pause_rolling_expectancy_trades' => (int) env('POLYMARKET_AUTO_PAUSE_ROLLING_EXPECTANCY_TRADES', 20),
    // Rule 6: Low profit factor (gross profit / gross loss < threshold after N trades).
    'auto_pause_min_profit_factor' => (float) env('POLYMARKET_AUTO_PAUSE_MIN_PROFIT_FACTOR', 1.0),
    'auto_pause_profit_factor_min_trades' => (int) env('POLYMARKET_AUTO_PAUSE_PROFIT_FACTOR_MIN_TRADES', 10),

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

    // Wallet discovery — auto-discover top traders from the leaderboard.
    'discover_min_pnl' => (float) env('POLYMARKET_DISCOVER_MIN_PNL', 500),
    'discover_min_volume' => (float) env('POLYMARKET_DISCOVER_MIN_VOLUME', 10000),
    'discover_time_period' => env('POLYMARKET_DISCOVER_TIME_PERIOD', 'WEEK'),
    'discover_category' => env('POLYMARKET_DISCOVER_CATEGORY', 'OVERALL'),
    'discover_limit' => (int) env('POLYMARKET_DISCOVER_LIMIT', 20),
    'discover_max_auto_add' => (int) env('POLYMARKET_DISCOVER_MAX_AUTO_ADD', 3),
];
