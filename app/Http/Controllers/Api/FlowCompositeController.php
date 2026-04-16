<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FlowComposite;
use App\Models\FlowCompositeEdge;
use App\Models\FlowCompositeNode;
use App\Models\FlowNode;
use App\Services\Flow\ModuleRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * API per i moduli compositi (sotto-grafi riusabili).
 *
 * Gestisce sia le proprietà del composito (key, label, descrizione) che il
 * suo sotto-grafo interno (nodes + edges), con gli stessi pattern di
 * FlowGraphController ma scoped al composite_id.
 */
class FlowCompositeController extends Controller
{
    public function __construct(private readonly ModuleRegistry $registry) {}

    /* ───────── CRUD composite metadata ───────── */

    public function index(): JsonResponse
    {
        return response()->json([
            'composites' => FlowComposite::orderBy('label')->get()->map(fn(FlowComposite $c) => [
                'id'          => $c->id,
                'key'         => $c->key,
                'label'       => $c->label,
                'description' => $c->description,
                'icon'        => $c->icon,
                'category'    => $c->category,
                'node_count'  => $c->nodes()->count(),
            ]),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'key'         => 'nullable|string|max:64',
            'label'       => 'required|string|max:120',
            'description' => 'nullable|string',
            'icon'        => 'nullable|string|max:64',
            'category'    => 'nullable|string|max:32',
        ]);

        $key = $data['key'] ?? Str::slug($data['label'], '_');
        if ($key === '') $key = 'composite_' . substr(md5($data['label'] . microtime()), 0, 8);
        $key = $this->uniqueKey($key);

        $composite = FlowComposite::create([
            'key'         => $key,
            'label'       => $data['label'],
            'description' => $data['description'] ?? null,
            'icon'        => $data['icon'] ?? null,
            'category'    => $data['category'] ?? 'custom',
        ]);

        $this->registry->invalidateComposites();
        return response()->json($composite, 201);
    }

    public function update(Request $request, FlowComposite $composite): JsonResponse
    {
        $data = $request->validate([
            'label'       => 'nullable|string|max:120',
            'description' => 'nullable|string',
            'icon'        => 'nullable|string|max:64',
            'category'    => 'nullable|string|max:32',
        ]);

        $composite->fill($data)->save();
        $this->registry->invalidateComposites();
        return response()->json($composite);
    }

    public function destroy(FlowComposite $composite): JsonResponse
    {
        $inUse = FlowNode::where('module_key', $composite->key)->count();
        if ($inUse > 0) {
            return response()->json([
                'error'   => 'in_use',
                'message' => "Impossibile eliminare: {$inUse} nodo/i del grafo principale lo usano ancora.",
                'count'   => $inUse,
            ], 422);
        }

        $composite->delete(); // cascade su nodes + edges
        $this->registry->invalidateComposites();
        return response()->json(['ok' => true]);
    }

    /* ───────── Sotto-grafo (nodes + edges) ───────── */

    public function graph(FlowComposite $composite): JsonResponse
    {
        $nodes = $composite->nodes->map(function (FlowCompositeNode $n) {
            $module = $this->registry->instantiate($n->module_key, $n->config ?? []);
            return [
                'id'           => $n->id,
                'module_key'   => $n->module_key,
                'module_label' => $module?->meta()->label ?? $n->module_key,
                'category'     => $module?->meta()->category ?? 'other',
                'icon'         => $module?->meta()->icon ?? 'box',
                'label'        => $n->label,
                'config'       => $n->config ?? [],
                'position'     => $n->position ?? ['x' => 0, 'y' => 0],
                'is_entry'     => (bool) $n->is_entry,
                // entry_trigger non serve nei sotto-grafi (l'ingresso è
                // chiamato dal parent, non da un trigger utente)
                'entry_trigger' => null,
                'outputs'      => $module?->outputs() ?? ['out' => 'Continua'],
            ];
        });

        $edges = $composite->edges->map(fn(FlowCompositeEdge $e) => [
            'id'           => $e->id,
            'from_node_id' => $e->from_node_id,
            'from_port'    => $e->from_port,
            'to_node_id'   => $e->to_node_id,
            'to_port'      => $e->to_port,
        ]);

        return response()->json([
            'composite' => $composite,
            'nodes'     => $nodes,
            'edges'     => $edges,
        ]);
    }

    public function createNode(Request $request, FlowComposite $composite): JsonResponse
    {
        $data = $request->validate([
            'module_key' => 'required|string',
            'label'      => 'nullable|string|max:120',
            'config'     => 'nullable|array',
            'position'   => 'nullable|array',
            'is_entry'   => 'nullable|boolean',
        ]);

        if (!$this->registry->has($data['module_key'])) {
            return response()->json(['error' => 'unknown_module'], 422);
        }

        // Se viene creato come entry, sbianca eventuale entry precedente:
        // il sotto-grafo ha UN SOLO ingresso.
        if ($data['is_entry'] ?? false) {
            FlowCompositeNode::where('composite_id', $composite->id)
                ->where('is_entry', true)
                ->update(['is_entry' => false]);
        }

        $node = FlowCompositeNode::create([
            'composite_id' => $composite->id,
            'module_key'   => $data['module_key'],
            'label'        => $data['label'] ?? null,
            'config'       => $data['config'] ?? [],
            'position'     => $data['position'] ?? ['x' => 0, 'y' => 0],
            'is_entry'     => $data['is_entry'] ?? false,
        ]);

        $this->registry->invalidateComposites();
        return response()->json($node, 201);
    }

    public function updateNode(Request $request, FlowComposite $composite, FlowCompositeNode $node): JsonResponse
    {
        abort_unless($node->composite_id === $composite->id, 404);

        $data = $request->validate([
            'label'    => 'nullable|string|max:120',
            'config'   => 'nullable|array',
            'position' => 'nullable|array',
            'is_entry' => 'nullable|boolean',
        ]);

        if (($data['is_entry'] ?? false) && !$node->is_entry) {
            FlowCompositeNode::where('composite_id', $composite->id)
                ->where('is_entry', true)
                ->where('id', '!=', $node->id)
                ->update(['is_entry' => false]);
        }

        $node->fill($data)->save();
        $this->registry->invalidateComposites();
        return response()->json($node);
    }

    public function deleteNode(FlowComposite $composite, FlowCompositeNode $node): JsonResponse
    {
        abort_unless($node->composite_id === $composite->id, 404);
        $node->delete();
        $this->registry->invalidateComposites();
        return response()->json(['ok' => true]);
    }

    public function savePositions(Request $request, FlowComposite $composite): JsonResponse
    {
        $data = $request->validate([
            'positions'            => 'required|array',
            'positions.*.id'       => 'required|integer',
            'positions.*.position' => 'required|array',
        ]);

        DB::transaction(function () use ($data, $composite) {
            foreach ($data['positions'] as $row) {
                FlowCompositeNode::where('composite_id', $composite->id)
                    ->where('id', $row['id'])
                    ->update(['position' => $row['position']]);
            }
        });

        return response()->json(['ok' => true]);
    }

    public function createEdge(Request $request, FlowComposite $composite): JsonResponse
    {
        $data = $request->validate([
            'from_node_id' => 'required|integer',
            'from_port'    => 'nullable|string|max:64',
            'to_node_id'   => 'required|integer',
            'to_port'      => 'nullable|string|max:64',
        ]);

        // Entrambi i nodi devono appartenere a questo composito.
        $valid = FlowCompositeNode::where('composite_id', $composite->id)
            ->whereIn('id', [$data['from_node_id'], $data['to_node_id']])
            ->count();
        if ($valid < 2) {
            return response()->json(['error' => 'cross_composite'], 422);
        }

        $edge = FlowCompositeEdge::create([
            'composite_id' => $composite->id,
            'from_node_id' => $data['from_node_id'],
            'from_port'    => $data['from_port'] ?? 'out',
            'to_node_id'   => $data['to_node_id'],
            'to_port'      => $data['to_port']   ?? 'in',
        ]);

        return response()->json($edge, 201);
    }

    public function deleteEdge(FlowComposite $composite, FlowCompositeEdge $edge): JsonResponse
    {
        abort_unless($edge->composite_id === $composite->id, 404);
        $edge->delete();
        return response()->json(['ok' => true]);
    }

    /* ───────── Helpers ───────── */

    private function uniqueKey(string $base): string
    {
        if (!FlowComposite::where('key', $base)->exists()) return $base;
        $i = 2;
        do {
            $candidate = "{$base}_{$i}";
            $i++;
        } while (FlowComposite::where('key', $candidate)->exists() && $i < 100);
        return $candidate;
    }
}
