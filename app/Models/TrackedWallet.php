<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TrackedWallet extends Model
{
    protected $fillable = ['address', 'name', 'profile_slug', 'is_paused', 'paused_at', 'pause_reason'];

    protected function casts(): array
    {
        return [
            'is_paused' => 'boolean',
            'paused_at' => 'datetime',
        ];
    }
}
