<?php

namespace App\Services;

use App\Models\BotMeta;

/**
 * Runtime-configurable settings with DB override and env/config fallback.
 *
 * Settings stored in BotMeta with key prefix "setting:".
 * Reading: DB override > config('polymarket.{key}') > provided default.
 */
class Setting
{
    private const PREFIX = 'setting:';

    /**
     * All settings exposed in the Settings UI, with their types and defaults.
     * Type is used for casting on read and validation on write.
     */
    public const SCHEMA = [
        // Trade Sizing
        'fixed_amount_override' => ['type' => 'float', 'nullable' => true, 'label' => 'Fixed Amount Override (USDC)', 'group' => 'sizing'],
        'fixed_amount_usdc' => ['type' => 'float', 'label' => 'Fallback Amount (USDC)', 'group' => 'sizing'],
        'sizing_min' => ['type' => 'float', 'label' => 'Min Amount (USDC)', 'group' => 'sizing'],
        'sizing_high_pct' => ['type' => 'float', 'label' => 'High Score (70+) %', 'group' => 'sizing'],
        'sizing_high_max' => ['type' => 'float', 'label' => 'High Score Max (USDC)', 'group' => 'sizing'],
        'sizing_mid_pct' => ['type' => 'float', 'label' => 'Mid Score (50-69) %', 'group' => 'sizing'],
        'sizing_mid_max' => ['type' => 'float', 'label' => 'Mid Score Max (USDC)', 'group' => 'sizing'],
        'sizing_low_pct' => ['type' => 'float', 'label' => 'Low Score (30-49) %', 'group' => 'sizing'],
        'sizing_low_max' => ['type' => 'float', 'label' => 'Low Score Max (USDC)', 'group' => 'sizing'],
        'use_kelly_sizing' => ['type' => 'bool', 'label' => 'Use Kelly Criterion Sizing', 'group' => 'sizing'],
        'kelly_fraction_multiplier' => ['type' => 'float', 'label' => 'Kelly Fraction Multiplier', 'group' => 'sizing'],
        'kelly_min_trades' => ['type' => 'int', 'label' => 'Kelly Min Trades', 'group' => 'sizing'],

        // Risk Limits
        'max_position_usdc' => ['type' => 'float', 'label' => 'Max Per-Market Exposure (USDC)', 'group' => 'limits'],
        'max_wallet_exposure_usdc' => ['type' => 'float', 'label' => 'Max Per-Wallet Exposure (USDC)', 'group' => 'limits'],
        'max_global_market_usdc' => ['type' => 'float', 'label' => 'Max Global Per-Market Exposure (USDC)', 'group' => 'limits'],
        'price_tolerance' => ['type' => 'float', 'label' => 'Price Tolerance', 'group' => 'limits'],
        'min_trade_price' => ['type' => 'float', 'label' => 'Min Trade Price', 'group' => 'limits'],

        // Trade Behavior
        'max_trade_age_seconds' => ['type' => 'int', 'label' => 'Max Trade Age (sec)', 'group' => 'behavior'],
        'momentum_filter' => ['type' => 'bool', 'label' => 'Momentum Confirmation Filter', 'group' => 'behavior'],
        'copy_sells' => ['type' => 'bool', 'label' => 'Copy Sell Trades', 'group' => 'behavior'],
        'dry_run' => ['type' => 'bool', 'label' => 'Dry Run Mode', 'group' => 'behavior'],
        'pending_order_ttl_minutes' => ['type' => 'int', 'label' => 'Pending Order TTL (min)', 'group' => 'behavior'],
        'trade_coalesce_window_seconds' => ['type' => 'int', 'label' => 'Trade Coalesce Window (sec)', 'group' => 'behavior'],
        'max_position_age_hours' => ['type' => 'int', 'label' => 'Max Position Age (hours)', 'group' => 'behavior'],
        'max_market_duration_days' => ['type' => 'int', 'label' => 'Max Market Duration (days)', 'group' => 'behavior'],
        'enable_tp_sl' => ['type' => 'bool', 'label' => 'Take-Profit / Stop-Loss', 'group' => 'behavior'],
        'tp_percentage' => ['type' => 'float', 'label' => 'Take-Profit %', 'group' => 'behavior'],
        'sl_percentage' => ['type' => 'float', 'label' => 'Stop-Loss %', 'group' => 'behavior'],

        // Polling
        'poll_batch_size' => ['type' => 'int', 'label' => 'Poll Batch Size', 'group' => 'polling'],
        'poll_batch_delay_ms' => ['type' => 'int', 'label' => 'Batch Delay (ms)', 'group' => 'polling'],
        'inactive_wallet_days' => ['type' => 'int', 'label' => 'Inactive Wallet Threshold (days)', 'group' => 'polling'],
        'inactive_poll_interval_seconds' => ['type' => 'int', 'label' => 'Inactive Poll Interval (sec)', 'group' => 'polling'],

        // Auto-Pause Rules
        'auto_pause_enabled' => ['type' => 'bool', 'label' => 'Enable Auto-Pause', 'group' => 'auto_pause'],
        'auto_pause_grace_period_trades' => ['type' => 'int', 'label' => 'Grace Period (min trades before rules apply)', 'group' => 'auto_pause'],
        'auto_pause_max_unrealized_loss' => ['type' => 'float', 'label' => 'Max Unrealized Loss ($)', 'group' => 'auto_pause'],
        'auto_pause_min_exposure' => ['type' => 'float', 'label' => 'Min Exposure for Loss Ratio ($)', 'group' => 'auto_pause'],
        'auto_pause_max_exposure_loss_ratio' => ['type' => 'float', 'label' => 'Max Exposure Loss Ratio', 'group' => 'auto_pause'],
        'auto_pause_bad_record_min_trades' => ['type' => 'int', 'label' => 'Bad Record Min Trades', 'group' => 'auto_pause'],
        'auto_pause_bad_record_max_win_rate' => ['type' => 'float', 'label' => 'Bad Record Max Win Rate (%)', 'group' => 'auto_pause'],
        'auto_pause_bad_record_max_loss' => ['type' => 'float', 'label' => 'Bad Record Max Loss ($)', 'group' => 'auto_pause'],
        'auto_pause_zero_win_min_trades' => ['type' => 'int', 'label' => 'Zero Wins Min Trades', 'group' => 'auto_pause'],
        'auto_pause_rolling_expectancy_trades' => ['type' => 'int', 'label' => 'Rolling Expectancy Window', 'group' => 'auto_pause'],
        'auto_pause_min_profit_factor' => ['type' => 'float', 'label' => 'Min Profit Factor', 'group' => 'auto_pause'],
        'auto_pause_profit_factor_min_trades' => ['type' => 'int', 'label' => 'Profit Factor Min Trades', 'group' => 'auto_pause'],

        // Market Categories
        'category_crypto' => ['type' => 'bool', 'label' => 'Crypto', 'group' => 'categories'],
        'category_politics' => ['type' => 'bool', 'label' => 'Politics', 'group' => 'categories'],
        'category_sports' => ['type' => 'bool', 'label' => 'Sports', 'group' => 'categories'],
        'category_pop_culture' => ['type' => 'bool', 'label' => 'Pop Culture', 'group' => 'categories'],
        'category_business' => ['type' => 'bool', 'label' => 'Business & Finance', 'group' => 'categories'],
        'category_science' => ['type' => 'bool', 'label' => 'Science & Weather', 'group' => 'categories'],
        'category_other' => ['type' => 'bool', 'label' => 'Other / Uncategorized', 'group' => 'categories'],

        // Resolution Sniping
        'snipe_enabled' => ['type' => 'bool', 'label' => 'Enable Scanner', 'group' => 'sniping'],
        'snipe_auto_trade' => ['type' => 'bool', 'label' => 'Auto-Trade', 'group' => 'sniping'],
        'snipe_min_probability' => ['type' => 'float', 'label' => 'Min Probability (0-1)', 'group' => 'sniping'],
        'snipe_max_hours' => ['type' => 'int', 'label' => 'Max Hours to Resolution', 'group' => 'sniping'],
        'snipe_trade_amount' => ['type' => 'float', 'label' => 'Trade Amount (USDC)', 'group' => 'sniping'],
    ];

    /**
     * Get a setting value: DB override > config('polymarket.{key}') > default.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $schema = self::SCHEMA[$key] ?? null;

        // Check DB override first.
        $dbValue = BotMeta::getValue(self::PREFIX . $key);

        if ($dbValue !== null) {
            return self::cast($dbValue, $schema['type'] ?? 'string', $schema['nullable'] ?? false);
        }

        // Fall back to config (which reads .env).
        // fixed_amount_override has no env counterpart — it's DB-only.
        if ($key === 'fixed_amount_override') {
            return $default;
        }

        $configValue = config('polymarket.' . $key);

        return $configValue !== null ? $configValue : $default;
    }

    /**
     * Set a setting value in DB. Pass null to clear the override (revert to env default).
     */
    public static function set(string $key, mixed $value): void
    {
        if ($value === null || $value === '') {
            // Remove override — fall back to env.
            BotMeta::where('key', self::PREFIX . $key)->delete();

            return;
        }

        BotMeta::setValue(self::PREFIX . $key, (string) $value);
    }

    /**
     * Check if a setting has a DB override (vs using env default).
     */
    public static function hasOverride(string $key): bool
    {
        return BotMeta::getValue(self::PREFIX . $key) !== null;
    }

    /**
     * Get all settings with current values, defaults, and override status.
     */
    public static function all(): array
    {
        $result = [];

        foreach (self::SCHEMA as $key => $meta) {
            $dbValue = BotMeta::getValue(self::PREFIX . $key);
            $hasOverride = $dbValue !== null;
            $currentValue = self::get($key);

            // The env/config default (what you'd get without a DB override).
            $envDefault = $key === 'fixed_amount_override'
                ? null
                : config('polymarket.' . $key);

            $result[$key] = [
                'value' => $currentValue,
                'default' => $envDefault,
                'has_override' => $hasOverride,
                'label' => $meta['label'],
                'type' => $meta['type'],
                'group' => $meta['group'],
                'nullable' => $meta['nullable'] ?? false,
            ];
        }

        return $result;
    }

    /**
     * Cast a string DB value to the appropriate PHP type.
     */
    private static function cast(string $value, string $type, bool $nullable = false): mixed
    {
        if ($nullable && ($value === '' || $value === 'null')) {
            return null;
        }

        return match ($type) {
            'float' => (float) $value,
            'int' => (int) $value,
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            default => $value,
        };
    }
}
