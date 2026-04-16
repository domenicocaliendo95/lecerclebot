<?php

namespace App\Services\Channel;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Registry dei ChannelAdapter attivi. Scopre via filesystem tutte le classi
 * in app/Services/Channel/Adapters che implementano ChannelAdapter.
 *
 * Il FlowRunner chiede `get($key)` per spedire su un canale specifico.
 * Se l'adapter non esiste, il dispatch è un no-op con log (non esplode).
 */
class ChannelRegistry
{
    /** @var array<string, ChannelAdapter> */
    private array $byKey = [];

    private bool $booted = false;

    public function boot(): void
    {
        if ($this->booted) return;
        $this->booted = true;

        $dir = app_path('Services/Channel/Adapters');
        if (!is_dir($dir)) return;

        foreach (File::allFiles($dir) as $file) {
            $relative = Str::after($file->getRealPath(), $dir . DIRECTORY_SEPARATOR);
            $class    = 'App\\Services\\Channel\\Adapters\\' . str_replace(
                ['/', '.php'], ['\\', ''], $relative
            );

            if (!class_exists($class)) continue;

            $reflection = new \ReflectionClass($class);
            if (!$reflection->implementsInterface(ChannelAdapter::class)) continue;
            if ($reflection->isAbstract()) continue;

            /** @var ChannelAdapter $adapter */
            $adapter = app($class); // dependency injection Laravel
            $this->byKey[$adapter->key()] = $adapter;
        }
    }

    public function get(string $key): ?ChannelAdapter
    {
        $this->boot();
        return $this->byKey[$key] ?? null;
    }

    public function has(string $key): bool
    {
        $this->boot();
        return isset($this->byKey[$key]);
    }

    /**
     * @return array<int, array{key:string,class:string}>
     */
    public function list(): array
    {
        $this->boot();
        return array_map(
            fn(ChannelAdapter $a) => ['key' => $a->key(), 'class' => $a::class],
            array_values($this->byKey),
        );
    }
}
