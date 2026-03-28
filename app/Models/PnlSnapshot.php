<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PnlSnapshot extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'realized_pnl',
        'unrealized_pnl',
        'combined_pnl',
        'positions_value',
        'total_invested',
        'open_positions',
        'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'realized_pnl' => 'float',
            'unrealized_pnl' => 'float',
            'combined_pnl' => 'float',
            'positions_value' => 'float',
            'total_invested' => 'float',
            'recorded_at' => 'datetime',
        ];
    }
}
