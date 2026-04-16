<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FlowModulePreset;
use App\Services\Flow\ModuleRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * API per il pannello "Moduli disponibili" (/panel/moduli):
 *   - catalogo di tutti i moduli (built-in + preset) con stato on/off
 *   - toggle abilita/disabilita
 *   - CRUD dei preset (moduli virtuali con config preimpostata)
 */
class ModuleCatalogController extends Controller
{
    public function __construct(private readonly ModuleRegistry $registry) {}

    /* ───────── Catalogo ───────── */

    public function catalog(): JsonResponse
    {
        return response()->json([
            'modules'  => $this->registry->allMeta(),
            'builtins' => $this->registry->builtInList(),
        ]);
    }

    /**
     * Aggiorna on/off in bulk. Body: `{toggles: {module_key: bool, ...}}`
     */
    public function updateToggles(Request $request): JsonResponse
    {
        $data = $request->validate([
            'toggles'   => 'required|array',
            'toggles.*' => 'boolean',
        ]);

        $this->registry->setToggles($data['toggles']);

        return response()->json(['ok' => true, 'toggles' => $this->registry->toggles()]);
    }

    /* ───────── Preset CRUD ───────── */

    public function listPresets(): JsonResponse
    {
        return response()->json([
            'presets' => FlowModulePreset::orderBy('label')->get(),
        ]);
    }

    public function createPreset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'key'             => 'nullable|string|max:64',
            'base_module_key' => 'required|string|max:64',
            'label'           => 'required|string|max:120',
            'description'     => 'nullable|string',
            'icon'            => 'nullable|string|max:64',
            'category'        => 'nullable|string|max:32',
            'config_defaults' => 'nullable|array',
        ]);

        // Il base deve essere un modulo built-in, non un altro preset.
        if (!in_array($data['base_module_key'], array_column($this->registry->builtInList(), 'key'), true)) {
            return response()->json([
                'error'   => 'invalid_base',
                'message' => 'Il modulo base deve essere built-in, non un preset.',
            ], 422);
        }

        $key = $data['key'] ?? $this->generateKey($data['label']);
        if (FlowModulePreset::where('key', $key)->exists()) {
            $key = $this->generateUniqueKey($key);
        }

        $preset = FlowModulePreset::create([
            'key'             => $key,
            'base_module_key' => $data['base_module_key'],
            'label'           => $data['label'],
            'description'     => $data['description'] ?? null,
            'icon'            => $data['icon'] ?? null,
            'category'        => $data['category'] ?? null,
            'config_defaults' => $data['config_defaults'] ?? [],
        ]);

        $this->registry->invalidatePresets();

        return response()->json($preset, 201);
    }

    public function updatePreset(Request $request, FlowModulePreset $preset): JsonResponse
    {
        $data = $request->validate([
            'label'           => 'nullable|string|max:120',
            'description'     => 'nullable|string',
            'icon'            => 'nullable|string|max:64',
            'category'        => 'nullable|string|max:32',
            'config_defaults' => 'nullable|array',
        ]);

        $preset->fill($data)->save();
        $this->registry->invalidatePresets();

        return response()->json($preset);
    }

    public function deletePreset(FlowModulePreset $preset): JsonResponse
    {
        // Protezione: se esistono nodi che usano questo preset, rifiuta.
        $inUse = \App\Models\FlowNode::where('module_key', $preset->key)->count();
        if ($inUse > 0) {
            return response()->json([
                'error'   => 'in_use',
                'message' => "Impossibile eliminare: {$inUse} nodo/i lo stanno ancora usando.",
                'count'   => $inUse,
            ], 422);
        }

        $preset->delete();
        $this->registry->invalidatePresets();

        return response()->json(['ok' => true]);
    }

    /* ───────── Helpers ───────── */

    private function generateKey(string $label): string
    {
        $slug = Str::slug($label, '_');
        return $slug !== '' ? $slug : 'preset_' . substr(md5($label . microtime()), 0, 8);
    }

    private function generateUniqueKey(string $base): string
    {
        $i = 2;
        do {
            $candidate = "{$base}_{$i}";
            $i++;
        } while (FlowModulePreset::where('key', $candidate)->exists() && $i < 100);
        return $candidate;
    }
}
