<?php

namespace App\Services\Flow;

use App\Models\BotSetting;
use App\Models\FlowModulePreset;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Registry dei moduli disponibili.
 *
 * Combina due fonti:
 *   1. Moduli "built-in": classi PHP in app/Services/Flow/Modules che estendono
 *      Module. Scoperte via scansione filesystem.
 *   2. Moduli "preset": record in flow_module_presets che configurano in anticipo
 *      un modulo base. A runtime vengono risolti al base_module_key fondendo
 *      config_defaults con la config del nodo.
 *
 * Supporta on/off via bot_settings['flow_module_toggles'] — un dict
 * {module_key: bool}. I moduli disabilitati vengono nascosti dal picker
 * (groupedByCategory) ma restano eseguibili dai grafi esistenti, per non
 * rompere flussi già cablati.
 *
 * Uso:
 *   $registry->instantiate('nome_modulo', $node->config);
 *   $registry->groupedByCategory(); // per il picker nell'editor
 *   $registry->allMeta();           // per il pannello "Moduli" (con disabled)
 */
class ModuleRegistry
{
    public const TOGGLES_SETTING_KEY = 'flow_module_toggles';

    /** @var array<string, class-string<Module>> */
    private array $builtInByKey = [];

    /** @var array<string, ModuleMeta> */
    private array $builtInMeta = [];

    /** @var array<string, FlowModulePreset> */
    private array $presetsByKey = [];

    /** @var array<string, ModuleMeta> */
    private array $presetMeta = [];

    private bool $bootedBuiltIn = false;
    private bool $bootedPresets = false;

    /* ───────── Boot ───────── */

    public function bootBuiltIn(): void
    {
        if ($this->bootedBuiltIn) {
            return;
        }
        $this->bootedBuiltIn = true;

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

            if (!class_exists($class) || !is_subclass_of($class, Module::class)) {
                continue;
            }

            /** @var Module $probe */
            $probe = new $class([]);
            $meta  = $probe->meta();
            $this->builtInByKey[$meta->key] = $class;
            $this->builtInMeta[$meta->key]  = $meta;
        }

        ksort($this->builtInByKey);
    }

    public function bootPresets(): void
    {
        if ($this->bootedPresets) {
            return;
        }
        $this->bootedPresets = true;

        try {
            $presets = FlowModulePreset::all();
        } catch (\Throwable $e) {
            // Tabella non ancora migrata — ignora in modo grazioso.
            Log::warning('ModuleRegistry: flow_module_presets not available', [
                'error' => $e->getMessage(),
            ]);
            return;
        }

        foreach ($presets as $preset) {
            $this->presetsByKey[$preset->key] = $preset;
            $this->presetMeta[$preset->key]   = $this->makePresetMeta($preset);
        }
    }

    private function boot(): void
    {
        $this->bootBuiltIn();
        $this->bootPresets();
    }

    /* ───────── Istanziazione ───────── */

    /**
     * Crea un'istanza del modulo applicando la config del nodo. Se `$key` è un
     * preset, viene risolto al modulo base con config_defaults unite al config
     * passato (il config del nodo vince su quello del preset).
     */
    public function instantiate(string $key, array $config = []): ?Module
    {
        $this->boot();

        // Preset → base class + merge config_defaults.
        if (isset($this->presetsByKey[$key])) {
            $preset = $this->presetsByKey[$key];
            $baseClass = $this->builtInByKey[$preset->base_module_key] ?? null;
            if ($baseClass === null) {
                Log::warning('ModuleRegistry: preset base missing', [
                    'preset' => $key,
                    'base'   => $preset->base_module_key,
                ]);
                return null;
            }
            $merged = array_replace_recursive(
                $preset->config_defaults ?? [],
                $config,
            );
            return new $baseClass($merged);
        }

        $class = $this->builtInByKey[$key] ?? null;
        if ($class === null) {
            return null;
        }
        return new $class($config);
    }

    public function has(string $key): bool
    {
        $this->boot();
        return isset($this->builtInByKey[$key]) || isset($this->presetsByKey[$key]);
    }

    /* ───────── Meta ───────── */

    /**
     * Meta di un singolo modulo (built-in o preset).
     */
    public function meta(string $key): ?ModuleMeta
    {
        $this->boot();
        return $this->builtInMeta[$key] ?? $this->presetMeta[$key] ?? null;
    }

    /**
     * Tutti i meta (built-in + preset), senza filtri di abilitazione.
     * Ogni voce è arricchita con:
     *   - `type`: "builtin" | "preset"
     *   - `base_module_key`: presente solo per preset
     *   - `enabled`: stato on/off corrente
     *
     * @return array<int, array>
     */
    public function allMeta(): array
    {
        $this->boot();

        $toggles = $this->toggles();
        $out = [];

        foreach ($this->builtInMeta as $key => $meta) {
            $out[] = $meta->toArray() + [
                'type'    => 'builtin',
                'enabled' => $toggles[$key] ?? true,
            ];
        }

        foreach ($this->presetMeta as $key => $meta) {
            $preset = $this->presetsByKey[$key];
            $out[] = $meta->toArray() + [
                'type'            => 'preset',
                'base_module_key' => $preset->base_module_key,
                'enabled'         => $toggles[$key] ?? true,
                'config_defaults' => $preset->config_defaults ?? [],
            ];
        }

        usort($out, fn($a, $b) => [$a['category'], $a['label']] <=> [$b['category'], $b['label']]);
        return $out;
    }

    /**
     * Raggruppato per categoria, FILTRATO per abilitazione (i moduli disabilitati
     * non appaiono). Usato dal picker dell'editor del flusso.
     *
     * @return array<string, array<int, array>>
     */
    public function groupedByCategory(): array
    {
        $this->boot();
        $toggles = $this->toggles();

        $out = [];
        foreach (array_merge($this->builtInMeta, $this->presetMeta) as $key => $meta) {
            if (!($toggles[$key] ?? true)) {
                continue;
            }
            $row = $meta->toArray();
            if (isset($this->presetsByKey[$key])) {
                $row['type'] = 'preset';
                $row['base_module_key'] = $this->presetsByKey[$key]->base_module_key;
            } else {
                $row['type'] = 'builtin';
            }
            $out[$meta->category] ??= [];
            $out[$meta->category][] = $row;
        }

        foreach ($out as &$group) {
            usort($group, fn($a, $b) => strcmp($a['label'], $b['label']));
        }
        unset($group);

        ksort($out);
        return $out;
    }

    /* ───────── Toggle on/off ───────── */

    /**
     * Mappa {module_key: bool} letta da bot_settings. Default: vuota (tutti abilitati).
     */
    public function toggles(): array
    {
        $raw = BotSetting::get(self::TOGGLES_SETTING_KEY, []);
        return is_array($raw) ? $raw : [];
    }

    /**
     * Aggiorna on/off in bulk. Accetta `{module_key: bool}` da fondere col corrente.
     */
    public function setToggles(array $patch): void
    {
        $current = $this->toggles();
        foreach ($patch as $k => $v) {
            $current[$k] = (bool) $v;
        }
        BotSetting::set(self::TOGGLES_SETTING_KEY, $current);
    }

    /* ───────── Preset helpers ───────── */

    /**
     * Ricostruisce meta virtuale di un preset, tenendo il config_schema dal base.
     */
    private function makePresetMeta(FlowModulePreset $preset): ModuleMeta
    {
        $baseMeta = $this->builtInMeta[$preset->base_module_key] ?? null;
        $baseSchema = $baseMeta?->configSchema ?? [];
        $category = $preset->category ?: ($baseMeta?->category ?? 'custom');
        $icon     = $preset->icon     ?: ($baseMeta?->icon ?? 'sparkles');

        return new ModuleMeta(
            key: $preset->key,
            label: $preset->label,
            category: $category,
            description: $preset->description ?? ($baseMeta?->description ?? ''),
            configSchema: $baseSchema,
            icon: $icon,
        );
    }

    /**
     * Invalidare la cache presets dopo un create/update/delete, così il registry
     * non servirebbe dati stantii in richieste successive.
     */
    public function invalidatePresets(): void
    {
        $this->presetsByKey = [];
        $this->presetMeta   = [];
        $this->bootedPresets = false;
    }

    /**
     * Elenco delle key dei soli moduli built-in (per dropdown "base module" del form preset).
     *
     * @return array<int, array{key:string,label:string,category:string}>
     */
    public function builtInList(): array
    {
        $this->boot();
        $out = [];
        foreach ($this->builtInMeta as $meta) {
            $out[] = ['key' => $meta->key, 'label' => $meta->label, 'category' => $meta->category];
        }
        usort($out, fn($a, $b) => [$a['category'], $a['label']] <=> [$b['category'], $b['label']]);
        return $out;
    }
}
