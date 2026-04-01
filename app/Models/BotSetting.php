<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BotSetting extends Model
{
    protected $primaryKey = 'key';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    protected $casts = [
        'value' => 'array',
    ];

    /**
     * Leggi un setting per chiave, con fallback.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $setting = self::find($key);
        return $setting ? $setting->value : $default;
    }

    /**
     * Scrivi un setting.
     */
    public static function set(string $key, mixed $value): void
    {
        self::updateOrCreate(
            ['key' => $key],
            ['value' => $value],
        );
    }
}
