<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SeenTrade extends Model
{
    public $timestamps = false;

    protected $fillable = ['transaction_hash', 'created_at'];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    /**
     * Prune to max entries, deleting oldest.
     */
    public static function prune(int $maxEntries = 50000): void
    {
        $count = self::count();
        if ($count <= $maxEntries) {
            return;
        }

        $excess = $count - $maxEntries;
        $ids = self::orderBy('id')->limit($excess)->pluck('id');
        self::whereIn('id', $ids)->delete();
    }
}
