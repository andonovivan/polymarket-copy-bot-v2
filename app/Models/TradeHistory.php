<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TradeHistory extends Model
{
    protected $table = 'trade_history';

    protected $fillable = [
        'asset_id',
        'market_slug',
        'copied_from_wallet',
        'buy_price',
        'sell_price',
        'shares',
        'pnl',
        'opened_at',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'buy_price' => 'float',
            'sell_price' => 'float',
            'shares' => 'float',
            'pnl' => 'float',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }
}
