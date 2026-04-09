<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class BotMessage extends Model
{
    protected $primaryKey = 'key';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = ['key', 'text', 'category', 'description'];

    private const CACHE_KEY = 'bot_messages_all';
    private const CACHE_TTL = 3600; // 1 ora

    /**
     * Leggi il testo di un messaggio per chiave, con fallback.
     */
    public static function get(string $key, string $fallback = ''): string
    {
        $messages = Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            return self::pluck('text', 'key')->toArray();
        });

        return $messages[$key] ?? $fallback;
    }

    /**
     * Invalida la cache dopo un aggiornamento.
     */
    public static function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    protected static function booted(): void
    {
        static::saved(fn () => self::clearCache());
        static::deleted(fn () => self::clearCache());
    }
}
