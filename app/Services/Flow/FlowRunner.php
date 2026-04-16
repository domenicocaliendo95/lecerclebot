<?php

namespace App\Services\Flow;

use App\Models\BotSession;
use App\Models\FlowCompositeEdge;
use App\Models\FlowCompositeNode;
use App\Models\FlowEdge;
use App\Models\FlowNode;
use App\Models\User;
use App\Services\WhatsAppService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Interprete del grafo di moduli.
 *
 * Ogni messaggio in arrivo risolve la sessione, trova il nodo corrente (o il
 * trigger d'ingresso adatto) e cammina il grafo finché non incontra un modulo
 * che chiede `wait`.
 *
 * ## Composites (sotto-grafi)
 *
 * Il runner supporta il richiamo di sotto-grafi riusabili ("compositi"):
 *   - un nodo del grafo può essere un `CompositeRefModule` (istanziato dal
 *     registry quando `module_key = "composite:<key>"`); esegue e restituisce
 *     `descendCompositeId` al runner
 *   - il runner "discende" nel sotto-grafo: push su `session.data.__flow_stack`
 *     di `{parent_node_id, parent_graph}`, jump al nodo `is_entry=true` del
 *     composito, cambia `currentGraph` al suo id
 *   - quando l'esecuzione raggiunge un `CompositeOutputModule`, restituisce
 *     `ascendPort`; il runner "risale": pop dallo stack, continua dal parent
 *     nella porta specificata
 *   - `wait` dentro un composito salva il cursore includendo il graph corrente
 *     (via `session.data.__cursor`), così al prossimo messaggio riprendiamo
 *     dentro il sotto-grafo senza perdere contesto
 */
class FlowRunner
{
    private const MAX_STEPS = 100;

    public function __construct(
        private readonly ModuleRegistry $registry,
        private readonly WhatsAppService $whatsApp,
    ) {}

    /* ───────────────────── Ingressi pubblici ───────────────────── */

    public function process(string $phone, string $input): void
    {
        $this->dispatch($phone, $this->run($phone, $input));
    }

    public function simulate(string $phone, string $input): array
    {
        return $this->run($phone, $input);
    }

    /* ───────────────────── Core ───────────────────── */

    private function run(string $phone, string $input): array
    {
        $queue = [];

        try {
            DB::transaction(function () use ($phone, $input, &$queue) {
                $user    = User::where('phone', $phone)->first();
                $session = $this->resolveSession($phone);
                $session->mergeData(['last_input' => $input]);

                [$startNode, $startGraph, $resuming] = $this->resolveStart($session, $input);
                if ($startNode === null) {
                    Log::warning('FlowRunner: no matching entry node', [
                        'phone' => $phone,
                        'input' => $input,
                    ]);
                    return;
                }

                $queue = $this->walk($startNode, $startGraph, $session, $phone, $input, $user, $resuming);
            });
        } catch (\Throwable $e) {
            Log::error('FlowRunner: fatal error', [
                'phone' => $phone,
                'input' => $input,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return [[
                'type' => 'text',
                'text' => 'Scusa, problema tecnico. Riprova tra poco 🙏',
            ]];
        }

        return $queue;
    }

    /**
     * Cammina il grafo. `$currentGraph` è null per il grafo principale, int
     * (composite_id) quando siamo dentro un sotto-grafo.
     *
     * @return array<int,array>
     */
    private function walk(
        FlowNode|FlowCompositeNode $start,
        ?int                        $currentGraph,
        BotSession                  $session,
        string                      $phone,
        string                      $input,
        ?User                       $user,
        bool                        $resuming,
    ): array {
        $queue = [];
        $node = $start;
        $currentInput = $input;

        for ($step = 0; $step < self::MAX_STEPS; $step++) {
            $module = $this->registry->instantiate($node->module_key, $node->config ?? []);
            if ($module === null) {
                Log::error('FlowRunner: unknown module', [
                    'module_key' => $node->module_key,
                    'node_id'    => $node->id,
                    'graph'      => $currentGraph ?? 'main',
                ]);
                break;
            }

            // Il FlowContext prende sempre un FlowNode per compatibilità.
            // All'interno di un composito facciamo una proiezione leggera
            // (i moduli in genere leggono solo config, non toccano $ctx->node).
            $ctx = new FlowContext(
                session:  $session,
                phone:    $phone,
                input:    $currentInput,
                user:     $user,
                node:     $this->projectNode($node),
                resuming: $resuming,
            );

            try {
                $result = $module->execute($ctx);
            } catch (\Throwable $e) {
                Log::error('FlowRunner: execute failed', [
                    'module_key' => $node->module_key,
                    'node_id'    => $node->id,
                    'error'      => $e->getMessage(),
                ]);
                break;
            }

            if (!empty($result->data)) {
                $session->mergeData($result->data);
            }
            foreach ($result->send as $msg) {
                $queue[] = $msg;
            }

            // ── WAIT ──────────────────────────────────────────────
            if ($result->wait) {
                $this->saveCursor($session, $node->id, $currentGraph);
                return $queue;
            }

            // ── DESCEND (entra in un composito) ───────────────────
            if ($result->descendCompositeId !== null) {
                $entry = FlowCompositeNode::where('composite_id', $result->descendCompositeId)
                    ->where('is_entry', true)
                    ->first();
                if ($entry === null) {
                    Log::error('FlowRunner: composite has no entry node', [
                        'composite_id' => $result->descendCompositeId,
                    ]);
                    break;
                }
                $this->pushStack($session, $node->id, $currentGraph);
                $node          = $entry;
                $currentGraph  = $result->descendCompositeId;
                $currentInput  = '';
                $resuming      = false;
                continue;
            }

            // ── ASCEND (esci dal composito) ───────────────────────
            if ($result->ascendPort !== null) {
                $frame = $this->popStack($session);
                if ($frame === null) {
                    // Nessuno stack: siamo al top-level con un composite_output
                    // orfano. Termina il flusso.
                    $this->clearCursor($session);
                    return $queue;
                }
                $parentId    = (int) ($frame['parent_node_id'] ?? 0);
                $parentGraph = $frame['parent_graph'];
                $parentNode  = $this->findNodeInGraph($parentId, $parentGraph);
                if ($parentNode === null) {
                    Log::error('FlowRunner: parent missing on ascend', ['frame' => $frame]);
                    $this->clearCursor($session);
                    return $queue;
                }
                $next = $this->nextNodeInGraph($parentNode, $result->ascendPort, $parentGraph);
                if ($next === null) {
                    $this->clearCursor($session);
                    return $queue;
                }
                $node         = $next;
                $currentGraph = $parentGraph;
                $currentInput = '';
                $resuming     = false;
                continue;
            }

            // ── Transizione normale via edge ──────────────────────
            $next = $this->nextNodeInGraph($node, $result->next, $currentGraph);
            if ($next === null) {
                $this->clearCursor($session);
                return $queue;
            }

            $node         = $next;
            $currentInput = '';
            $resuming     = false;
        }

        Log::warning('FlowRunner: MAX_STEPS reached', [
            'phone' => $phone,
            'last'  => $node->id ?? null,
        ]);
        return $queue;
    }

    /* ───────────────────── Cursor & stack ───────────────────── */

    /**
     * @return array{0: FlowNode|FlowCompositeNode|null, 1: ?int, 2: bool}
     *         [startNode, currentGraph, resuming]
     */
    private function resolveStart(BotSession $session, string $input): array
    {
        $cursor = $session->getData('__cursor');
        if (is_array($cursor) && isset($cursor['node_id'])) {
            $graph = $cursor['graph'] ?? null;
            $node  = $this->findNodeInGraph((int) $cursor['node_id'], is_int($graph) ? $graph : null);
            if ($node !== null) {
                return [$node, is_int($graph) ? $graph : null, true];
            }
        }

        if ($session->current_node_id) {
            $node = FlowNode::find($session->current_node_id);
            if ($node !== null) {
                return [$node, null, true];
            }
        }

        // Trigger search sul grafo principale.
        $triggers = FlowNode::where('is_entry', true)
            ->orderByRaw("CASE WHEN entry_trigger LIKE 'keyword:%' THEN 0 ELSE 1 END")
            ->get();
        foreach ($triggers as $trigger) {
            if ($this->matchesTrigger($trigger, $input)) {
                return [$trigger, null, false];
            }
        }
        return [null, null, false];
    }

    private function saveCursor(BotSession $session, int $nodeId, ?int $currentGraph): void
    {
        if ($currentGraph === null) {
            // Siamo nel grafo principale: usa la colonna nativa.
            $session->update(['current_node_id' => $nodeId]);
            $session->mergeData(['__cursor' => null]);
        } else {
            // Dentro un composito: usa __cursor JSON.
            $session->update(['current_node_id' => null]);
            $session->mergeData(['__cursor' => [
                'graph'   => $currentGraph,
                'node_id' => $nodeId,
            ]]);
        }
    }

    private function clearCursor(BotSession $session): void
    {
        $session->update(['current_node_id' => null]);
        $session->mergeData([
            '__cursor'     => null,
            '__flow_stack' => null,
        ]);
    }

    private function pushStack(BotSession $session, int $parentNodeId, ?int $parentGraph): void
    {
        $stack = (array) ($session->getData('__flow_stack') ?? []);
        $stack[] = [
            'parent_node_id' => $parentNodeId,
            'parent_graph'   => $parentGraph,
        ];
        $session->mergeData(['__flow_stack' => $stack]);
    }

    private function popStack(BotSession $session): ?array
    {
        $stack = (array) ($session->getData('__flow_stack') ?? []);
        if (empty($stack)) return null;
        $frame = array_pop($stack);
        $session->mergeData(['__flow_stack' => $stack]);
        return is_array($frame) ? $frame : null;
    }

    /* ───────────────────── Routing edge ───────────────────── */

    private function nextNodeInGraph(
        FlowNode|FlowCompositeNode $node,
        string                      $port,
        ?int                        $currentGraph,
    ): FlowNode|FlowCompositeNode|null {
        if ($currentGraph === null) {
            $edge = FlowEdge::where('from_node_id', $node->id)
                ->where('from_port', $port)
                ->first();
            return $edge ? FlowNode::find($edge->to_node_id) : null;
        }

        $edge = FlowCompositeEdge::where('composite_id', $currentGraph)
            ->where('from_node_id', $node->id)
            ->where('from_port', $port)
            ->first();
        return $edge ? FlowCompositeNode::find($edge->to_node_id) : null;
    }

    private function findNodeInGraph(int $id, ?int $currentGraph): FlowNode|FlowCompositeNode|null
    {
        return $currentGraph === null
            ? FlowNode::find($id)
            : FlowCompositeNode::where('composite_id', $currentGraph)->where('id', $id)->first();
    }

    private function matchesTrigger(FlowNode $trigger, string $input): bool
    {
        $t = $trigger->entry_trigger ?? 'first_message';
        if ($t === 'first_message') return true;
        if (str_starts_with($t, 'keyword:')) {
            $kw = trim(mb_strtolower(substr($t, 8)));
            return str_contains(mb_strtolower($input), $kw);
        }
        return false;
    }

    /* ───────────────────── Sessione ───────────────────── */

    private function resolveSession(string $phone): BotSession
    {
        return BotSession::firstOrCreate(
            ['phone' => $phone],
            ['state' => 'NEW', 'data' => []],
        );
    }

    /**
     * Moduli leggono `$ctx->node` principalmente per id/config; normalizziamo
     * i nodi compositi a FlowNode-like via cast di dati (i moduli non si
     * accorgono della differenza finché non persistono riferimenti al model).
     */
    private function projectNode(FlowNode|FlowCompositeNode $n): FlowNode
    {
        if ($n instanceof FlowNode) return $n;

        $proxy = new FlowNode();
        $proxy->id         = $n->id;
        $proxy->module_key = $n->module_key;
        $proxy->label      = $n->label;
        $proxy->config     = $n->config;
        $proxy->position   = $n->position;
        $proxy->is_entry   = (bool) $n->is_entry;
        return $proxy;
    }

    /* ───────────────────── Invio messaggi ───────────────────── */

    private function dispatch(string $phone, array $queue): void
    {
        foreach ($queue as $msg) {
            try {
                $type = $msg['type'] ?? 'text';
                if ($type === 'buttons' && !empty($msg['buttons'])) {
                    $this->whatsApp->sendButtons($phone, (string) ($msg['text'] ?? ''), (array) $msg['buttons']);
                } else {
                    $this->whatsApp->sendText($phone, (string) ($msg['text'] ?? ''));
                }
            } catch (\Throwable $e) {
                Log::warning('FlowRunner: dispatch failed', ['phone' => $phone, 'error' => $e->getMessage()]);
            }
        }
    }
}
