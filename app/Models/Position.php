<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Position extends Model
{
    protected $fillable = [
        'asset_id',
        'market_slug',
        'market_question',
        'market_image',
        'outcome',
        'copied_from_wallet',
        'shares',
        'exposure',
        'buy_price',
        'current_price',
        'tp_price',
        'sl_price',
        'market_status',
        'price_updated_at',
        'opened_at',
    ];

    protected function casts(): array
    {
        return [
            'shares' => 'float',
            'exposure' => 'float',
            'buy_price' => 'float',
            'current_price' => 'float',
            'tp_price' => 'float',
            'sl_price' => 'float',
            'opened_at' => 'datetime',
            'price_updated_at' => 'datetime',
        ];
    }
}
