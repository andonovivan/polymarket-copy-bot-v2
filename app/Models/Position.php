<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Position extends Model
{
    protected $fillable = [
        'asset_id',
        'shares',
        'exposure',
        'buy_price',
        'opened_at',
    ];

    protected function casts(): array
    {
        return [
            'shares' => 'float',
            'exposure' => 'float',
            'buy_price' => 'float',
            'opened_at' => 'datetime',
        ];
    }
}
