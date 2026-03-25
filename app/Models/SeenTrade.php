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
     * Uses a single subquery delete instead of loading IDs into PHP memory.
     */
    public static function prune(int $maxEntries = 50000): void
    {
        $count = self::count();
        if ($count <= $maxEntries) {
            return;
        }

        $excess = $count - $maxEntries;

        // Delete oldest rows directly — avoids loading IDs into memory.
        // MariaDB/MySQL doesn't support LIMIT in subquery DELETE, so use a
        // subquery to find the cutoff ID.
        $cutoffId = self::orderBy('id')->skip($excess)->value('id');
        if ($cutoffId) {
            self::where('id', '<', $cutoffId)->delete();
        }
    }
}
