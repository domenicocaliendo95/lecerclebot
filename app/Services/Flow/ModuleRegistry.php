<?php

namespace App\Services\Flow;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Registry dei moduli disponibili, con auto-discovery dalla directory
 * app/Services/Flow/Modules. Un modulo è una classe concreta che estende Module.
 *
 * Uso:
 *   $registry->instantiate('invia_bottoni', $node->config);
 *   $registry->all(); // array di ModuleMeta per l'editor
 */
class ModuleRegistry
{
    /** @var array<string, class-string<Module>> */
    private array $byKey = [];

    /** @var array<string, ModuleMeta> */
    private array $metaCache = [];

    private bool $booted = false;

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }
        $this->booted = true;

        $dir = app_path('Services/Flow/Modules');
        if (!is_dir($dir)) {
            return;
        }

        foreach (File::allFiles($dir) as $file) {
            $relative = Str::after($file->getRealPath(), $dir . DIRECTORY_SEPARATOR);
            $class    = 'App\\Services\\Flow\\Modules\\' . str_replace(
                ['/', '.php'],
                ['\\', ''],
                $relative
            );

            if (!class_exists($class)) {
                continue;
            }
            if (!is_subclass_of($class, Module::class)) {
                continue;
            }

            /** @var Module $probe */
            $probe = new $class([]);
            $meta  = $probe->meta();
            $this->byKey[$meta->key]     = $class;
            $this->metaCache[$meta->key] = $meta;
        }

        ksort($this->byKey);
    }

    /**
     * Istanzia un modulo applicando la config del nodo.
     */
    public function instantiate(string $key, array $config = []): ?Module
    {
        $this->boot();
        $class = $this->byKey[$key] ?? null;
        if ($class === null) {
            return null;
        }
        return new $class($config);
    }

    public function has(string $key): bool
    {
        $this->boot();
        return isset($this->byKey[$key]);
    }

    /**
     * Tutti i metadati, raggruppati per categoria. Usato dal module picker.
     *
     * @return array<string, array<int, array>>
     */
    public function groupedByCategory(): array
    {
        $this->boot();

        $out = [];
        foreach ($this->metaCache as $meta) {
            $out[$meta->category] ??= [];
            $out[$meta->category][] = $meta->toArray();
        }

        foreach ($out as &$group) {
            usort($group, fn($a, $b) => strcmp($a['label'], $b['label']));
        }
        unset($group);

        ksort($out);
        return $out;
    }

    /**
     * Meta di un singolo modulo (o null se non registrato).
     */
    public function meta(string $key): ?ModuleMeta
    {
        $this->boot();
        return $this->metaCache[$key] ?? null;
    }
}
