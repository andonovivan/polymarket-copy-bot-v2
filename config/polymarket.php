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
    'copy_sells' => env('POLYMARKET_COPY_SELLS', true),
    'poll_interval_seconds' => (int) env('POLYMARKET_POLL_INTERVAL_SECONDS', 30),
    'dry_run' => env('POLYMARKET_DRY_RUN', true),

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

    // Wallet discovery — auto-discover top traders from the leaderboard.
    'discover_min_pnl' => (float) env('POLYMARKET_DISCOVER_MIN_PNL', 500),
    'discover_min_volume' => (float) env('POLYMARKET_DISCOVER_MIN_VOLUME', 10000),
    'discover_time_period' => env('POLYMARKET_DISCOVER_TIME_PERIOD', 'WEEK'),
    'discover_category' => env('POLYMARKET_DISCOVER_CATEGORY', 'OVERALL'),
    'discover_limit' => (int) env('POLYMARKET_DISCOVER_LIMIT', 20),
    'discover_max_auto_add' => (int) env('POLYMARKET_DISCOVER_MAX_AUTO_ADD', 3),
];
