<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class BotFlowState extends Model
{
    protected $primaryKey = 'state';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'state', 'type', 'message_key', 'fallback_key',
        'buttons', 'category', 'description', 'sort_order',
        'position', 'is_custom',
    ];

    protected $casts = [
        'buttons'    => 'array',
        'position'   => 'array',
        'sort_order' => 'integer',
        'is_custom'  => 'boolean',
    ];

    private const CACHE_KEY = 'bot_flow_states_all';
    private const CACHE_TTL = 3600;

    /**
     * Recupera lo stato dal cache.
     */
    public static function getCached(string $state): ?self
    {
        $all = Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            return self::all()->keyBy('state')->toArray();
        });

        if (!isset($all[$state])) {
            return null;
        }

        $model = new self();
        $model->forceFill($all[$state]);
        $model->exists = true;

        return $model;
    }

    /**
     * Restituisce solo le label dei bottoni.
     */
    public function buttonLabels(): array
    {
        return array_column($this->buttons ?? [], 'label');
    }

    /**
     * Trova la configurazione del bottone che matcha una label.
     */
    public function findButton(string $label): ?array
    {
        foreach ($this->buttons ?? [] as $btn) {
            if (mb_strtolower($btn['label']) === mb_strtolower($label)) {
                return $btn;
            }
        }
        return null;
    }

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
