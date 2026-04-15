<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FlowEdge;
use App\Models\FlowNode;
use App\Services\Flow\ModuleRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * API per il nuovo editor visuale del flusso a grafo.
 *
 * Espone:
 *   - GET  /flow/modules  — registry dei moduli disponibili, raggruppati per categoria
 *   - GET  /flow/graph    — tutti i nodi + archi, già arricchiti con outputs calcolati
 *   - POST /flow/nodes    — crea un nodo da un module_key
 *   - PUT  /flow/nodes/positions       — bulk save posizioni (drag)
 *   - PUT  /flow/nodes/{node}          — aggiorna label/config/is_entry/entry_trigger
 *   - DELETE /flow/nodes/{node}        — elimina nodo (cascade sugli edges)
 *   - POST /flow/edges                 — crea un arco tra due porte
 *   - DELETE /flow/edges/{edge}        — elimina un arco
 */
class FlowGraphController extends Controller
{
    public function __construct(private readonly ModuleRegistry $registry) {}

    public function modules(): JsonResponse
    {
        return response()->json([
            'grouped' => $this->registry->groupedByCategory(),
        ]);
    }

    public function graph(): JsonResponse
    {
        $nodes = FlowNode::all()->map(function (FlowNode $n) {
            $module = $this->registry->instantiate($n->module_key, $n->config ?? []);
            return [
                'id'            => $n->id,
                'module_key'    => $n->module_key,
                'module_label'  => $module?->meta()->label ?? $n->module_key,
                'category'      => $module?->meta()->category ?? 'other',
                'icon'          => $module?->meta()->icon ?? 'box',
                'label'         => $n->label,
                'config'        => $n->config ?? [],
                'position'      => $n->position ?? ['x' => 0, 'y' => 0],
                'is_entry'      => (bool) $n->is_entry,
                'entry_trigger' => $n->entry_trigger,
                'outputs'       => $module?->outputs() ?? ['out' => 'Continua'],
            ];
        });

        $edges = FlowEdge::all()->map(fn(FlowEdge $e) => [
            'id'           => $e->id,
            'from_node_id' => $e->from_node_id,
            'from_port'    => $e->from_port,
            'to_node_id'   => $e->to_node_id,
            'to_port'      => $e->to_port,
        ]);

        return response()->json([
            'nodes' => $nodes,
            'edges' => $edges,
        ]);
    }

    public function createNode(Request $request): JsonResponse
    {
        $data = $request->validate([
            'module_key'    => 'required|string',
            'label'         => 'nullable|string|max:120',
            'config'        => 'nullable|array',
            'position'      => 'nullable|array',
            'is_entry'      => 'nullable|boolean',
            'entry_trigger' => 'nullable|string|max:64',
        ]);

        if (!$this->registry->has($data['module_key'])) {
            return response()->json(['error' => 'unknown_module'], 422);
        }

        $node = FlowNode::create([
            'module_key'    => $data['module_key'],
            'label'         => $data['label'] ?? null,
            'config'        => $data['config'] ?? [],
            'position'      => $data['position'] ?? ['x' => 0, 'y' => 0],
            'is_entry'      => $data['is_entry'] ?? false,
            'entry_trigger' => $data['entry_trigger'] ?? null,
        ]);

        return response()->json($node, 201);
    }

    public function updateNode(Request $request, FlowNode $node): JsonResponse
    {
        $data = $request->validate([
            'label'         => 'nullable|string|max:120',
            'config'        => 'nullable|array',
            'position'      => 'nullable|array',
            'is_entry'      => 'nullable|boolean',
            'entry_trigger' => 'nullable|string|max:64',
        ]);

        $node->fill($data)->save();
        return response()->json($node);
    }

    public function savePositions(Request $request): JsonResponse
    {
        $data = $request->validate([
            'positions'            => 'required|array',
            'positions.*.id'       => 'required|integer|exists:flow_nodes,id',
            'positions.*.position' => 'required|array',
        ]);

        DB::transaction(function () use ($data) {
            foreach ($data['positions'] as $row) {
                FlowNode::where('id', $row['id'])->update([
                    'position' => $row['position'],
                ]);
            }
        });

        return response()->json(['ok' => true]);
    }

    public function deleteNode(FlowNode $node): JsonResponse
    {
        $node->delete();
        return response()->json(['ok' => true]);
    }

    public function createEdge(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from_node_id' => 'required|integer|exists:flow_nodes,id',
            'from_port'    => 'nullable|string|max:64',
            'to_node_id'   => 'required|integer|exists:flow_nodes,id',
            'to_port'      => 'nullable|string|max:64',
        ]);

        $edge = FlowEdge::create([
            'from_node_id' => $data['from_node_id'],
            'from_port'    => $data['from_port'] ?? 'out',
            'to_node_id'   => $data['to_node_id'],
            'to_port'      => $data['to_port']   ?? 'in',
        ]);

        return response()->json($edge, 201);
    }

    public function deleteEdge(FlowEdge $edge): JsonResponse
    {
        $edge->delete();
        return response()->json(['ok' => true]);
    }
}
