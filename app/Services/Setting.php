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

        // Risk Limits
        'max_position_usdc' => ['type' => 'float', 'label' => 'Max Per-Market Exposure (USDC)', 'group' => 'limits'],
        'max_wallet_exposure_usdc' => ['type' => 'float', 'label' => 'Max Per-Wallet Exposure (USDC)', 'group' => 'limits'],
        'price_tolerance' => ['type' => 'float', 'label' => 'Price Tolerance', 'group' => 'limits'],
        'min_trade_price' => ['type' => 'float', 'label' => 'Min Trade Price', 'group' => 'limits'],

        // Trade Behavior
        'copy_sells' => ['type' => 'bool', 'label' => 'Copy Sell Trades', 'group' => 'behavior'],
        'dry_run' => ['type' => 'bool', 'label' => 'Dry Run Mode', 'group' => 'behavior'],
        'pending_order_ttl_minutes' => ['type' => 'int', 'label' => 'Pending Order TTL (min)', 'group' => 'behavior'],
        'trade_coalesce_window_seconds' => ['type' => 'int', 'label' => 'Trade Coalesce Window (sec)', 'group' => 'behavior'],

        // Polling
        'poll_batch_size' => ['type' => 'int', 'label' => 'Poll Batch Size', 'group' => 'polling'],
        'poll_batch_delay_ms' => ['type' => 'int', 'label' => 'Batch Delay (ms)', 'group' => 'polling'],
        'inactive_wallet_days' => ['type' => 'int', 'label' => 'Inactive Wallet Threshold (days)', 'group' => 'polling'],
        'inactive_poll_interval_seconds' => ['type' => 'int', 'label' => 'Inactive Poll Interval (sec)', 'group' => 'polling'],
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
