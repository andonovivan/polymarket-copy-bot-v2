<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PnlSummary extends Model
{
    public $timestamps = false;

    protected $table = 'pnl_summary';

    protected $fillable = [
        'total_realized',
        'total_trades',
        'winning_trades',
        'losing_trades',
    ];

    protected function casts(): array
    {
        return [
            'total_realized' => 'float',
            'total_trades' => 'integer',
            'winning_trades' => 'integer',
            'losing_trades' => 'integer',
        ];
    }

    /**
     * Get the singleton summary row.
     */
    public static function singleton(): self
    {
        return self::firstOrCreate(['id' => 1], [
            'total_realized' => 0,
            'total_trades' => 0,
            'winning_trades' => 0,
            'losing_trades' => 0,
        ]);
    }
}
