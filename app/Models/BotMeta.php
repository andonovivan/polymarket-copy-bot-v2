<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BotMeta extends Model
{
    protected $table = 'bot_meta';

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = ['key', 'value', 'updated_at'];

    /**
     * Get a meta value by key.
     */
    public static function getValue(string $key, mixed $default = null): mixed
    {
        $row = self::find($key);

        return $row ? $row->value : $default;
    }

    /**
     * Set a meta value by key.
     */
    public static function setValue(string $key, mixed $value): void
    {
        self::updateOrCreate(
            ['key' => $key],
            ['value' => (string) $value, 'updated_at' => now()],
        );
    }
}
