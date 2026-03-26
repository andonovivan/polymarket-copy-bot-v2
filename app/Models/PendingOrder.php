<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PendingOrder extends Model
{
    protected $fillable = [
        'order_id',
        'asset_id',
        'side',
        'price',
        'size',
        'amount_usdc',
        'copied_from_wallet',
        'market_slug',
        'status',
        'fill_price',
        'placed_at',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'float',
            'size' => 'float',
            'amount_usdc' => 'float',
            'fill_price' => 'float',
            'placed_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * Scope: only pending (unfilled, uncancelled) orders.
     */
    public function scopePending($query)
    {
        return $query->whereIn('status', ['live', 'delayed']);
    }

    /**
     * Check if this order has exceeded the TTL.
     */
    public function isExpired(int $ttlMinutes = 10): bool
    {
        return $this->placed_at->diffInMinutes(now()) >= $ttlMinutes;
    }

    /**
     * Prune resolved (filled/cancelled) orders older than the given number of days.
     */
    public static function prune(int $days = 7): int
    {
        return static::whereNotIn('status', ['live', 'delayed'])
            ->where('resolved_at', '<', now()->subDays($days))
            ->delete();
    }
}
