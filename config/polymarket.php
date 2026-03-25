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

    // Auto-pause thresholds — wallets are paused when ALL conditions are met.
    'auto_pause_min_trades' => (int) env('POLYMARKET_AUTO_PAUSE_MIN_TRADES', 10),
    'auto_pause_max_win_rate' => (float) env('POLYMARKET_AUTO_PAUSE_MAX_WIN_RATE', 30),
    'auto_pause_max_loss' => (float) env('POLYMARKET_AUTO_PAUSE_MAX_LOSS', -15),
];
