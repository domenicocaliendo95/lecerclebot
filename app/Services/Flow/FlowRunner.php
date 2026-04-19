<?php

namespace App\Services\Flow;

use App\Models\BotSession;
use App\Models\FlowCompositeEdge;
use App\Models\FlowCompositeNode;
use App\Models\FlowEdge;
use App\Models\FlowNode;
use App\Models\User;
use App\Services\Channel\ChannelRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Interprete del grafo di moduli, agnostico al canale.
 *
 * L'input entra via `process($channel, $externalId, $input)` dal controller
 * del canale (WhatsApp, Webchat, Telegram…); l'output viene dispatcato via
 * il `ChannelAdapter` corrispondente letto da `ChannelRegistry`.
 *
 * ## Composites (sotto-grafi)
 *
 * Il runner supporta il richiamo di sotto-grafi riusabili:
 *   - un nodo del grafo può essere un `CompositeRefModule` (istanziato dal
 *     registry quando il module_key corrisponde a un composite); esegue e
 *     restituisce `descendCompositeId` al runner
 *   - il runner push'a su `session.data.__flow_stack`
 *     `{parent_node_id, parent_graph}` e salta al nodo `is_entry=true` del
 *     composito, cambia `currentGraph` al suo id
 *   - quando l'esecuzione raggiunge un `CompositeOutputModule`, restituisce
 *     `ascendPort`; il runner pop'pa e continua dal parent nella porta emessa
 *   - un `wait` dentro un composito salva il cursore includendo il graph
 *     corrente (via `session.data.__cursor`), così il prossimo messaggio
 *     riprende dentro il sotto-grafo senza perdere contesto
 */
class FlowRunner
{
    private const MAX_STEPS = 100;

    public function __construct(
        private readonly ModuleRegistry  $registry,
        private readonly ChannelRegistry $channels,
    ) {}

    /* ───────────────────── Ingressi pubblici ───────────────────── */

    public function process(string $channel, string $externalId, string $input): void
    {
        $this->dispatch($channel, $externalId, $this->run($channel, $externalId, $input));
    }

    public function simulate(string $channel, string $externalId, string $input): array
    {
        return $this->run($channel, $externalId, $input);
    }

    /* ───────────────────── Core ───────────────────── */

    private function run(string $channel, string $externalId, string $input): array
    {
        $queue = [];

        try {
            DB::transaction(function () use ($channel, $externalId, $input, &$queue) {
                $session = $this->resolveSession($channel, $externalId);
                $user    = $this->resolveUser($channel, $externalId, $session);
                $session->mergeData(['last_input' => $input]);

                // Log messaggio in ingresso
                if ($input !== '') {
                    $session->appendHistory('user', $input);
                }

                [$startNode, $startGraph, $resuming] = $this->resolveStart($session, $input);
                if ($startNode === null) {
                    Log::warning('FlowRunner: no matching entry node', [
                        'channel'     => $channel,
                        'external_id' => $externalId,
                        'input'       => $input,
                    ]);
                    return;
                }

                $queue = $this->walk(
                    start:        $startNode,
                    currentGraph: $startGraph,
                    session:      $session,
                    channel:      $channel,
                    externalId:   $externalId,
                    input:        $input,
                    user:         $user,
                    resuming:     $resuming,
                );

                // Log messaggi in uscita nella history della sessione
                foreach ($queue as $msg) {
                    $text = (string) ($msg['text'] ?? '');
                    if ($text !== '') {
                        $session->appendHistory('bot', $text);
                    }
                }
            });
        } catch (\Throwable $e) {
            Log::error('FlowRunner: fatal error', [
                'channel'     => $channel,
                'external_id' => $externalId,
                'input'       => $input,
                'error'       => $e->getMessage(),
                'trace'       => $e->getTraceAsString(),
            ]);
            return [[
                'type' => 'text',
                'text' => 'Scusa, problema tecnico. Riprova tra poco 🙏',
            ]];
        }

        return $queue;
    }

    /**
     * @return array<int,array>
     */
    private function walk(
        FlowNode|FlowCompositeNode $start,
        ?int                        $currentGraph,
        BotSession                  $session,
        string                      $channel,
        string                      $externalId,
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

            $ctx = new FlowContext(
                session:    $session,
                channel:    $channel,
                externalId: $externalId,
                input:      $currentInput,
                user:       $user,
                node:       $this->projectNode($node),
                resuming:   $resuming,
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
                // currentInput preservato (vedi nota in fondo al loop)
                $resuming      = false;
                continue;
            }

            // ── ASCEND (esci dal composito) ───────────────────────
            if ($result->ascendPort !== null) {
                $frame = $this->popStack($session);
                if ($frame === null) {
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
                // currentInput preservato
                $resuming     = false;
                continue;
            }

            // ── Transizione normale via edge ──────────────────────
            $next = $this->nextNodeInGraph($node, $result->next, $currentGraph);
            if ($next === null) {
                $this->clearCursor($session);
                return $queue;
            }

            $node     = $next;
            $resuming = false;
            // NON azzeriamo currentInput: i moduli successivi nella catena
            // possono aver bisogno dell'input originale (es. parse_risultato
            // dopo invia_bottoni, parse_data dopo attendi_input).
            // I moduli che non necessitano dell'input semplicemente lo ignorano.
        }

        Log::warning('FlowRunner: MAX_STEPS reached', [
            'channel'     => $channel,
            'external_id' => $externalId,
            'last'        => $node->id ?? null,
        ]);
        return $queue;
    }

    /* ───────────────────── Cursor & stack ───────────────────── */

    /**
     * @return array{0: FlowNode|FlowCompositeNode|null, 1: ?int, 2: bool}
     */
    private function resolveStart(BotSession $session, string $input): array
    {
        // ── 1. Keyword globali: SEMPRE controllate prima del cursore.
        // Se l'utente scrive "menu" a metà di un flusso, interrompe
        // e riparte dal trigger keyword corrispondente.
        $keywordMatch = $this->findKeywordTrigger($input);
        if ($keywordMatch !== null) {
            $this->clearCursor($session);
            return [$keywordMatch, null, false];
        }

        // ── 2. Cursore attivo: riprendi da dove eri rimasto.
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

        // ── 3. Nessun cursore: cerca un trigger che matchi.
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

    /**
     * Cerca tra i trigger keyword:* se l'input matcha. Restituisce il nodo
     * trigger se trovato, null altrimenti. I trigger scheduler:* sono esclusi.
     */
    private function findKeywordTrigger(string $input): ?FlowNode
    {
        if (trim($input) === '') return null;

        $triggers = FlowNode::where('is_entry', true)
            ->where('entry_trigger', 'like', 'keyword:%')
            ->get();

        $lower = mb_strtolower(trim($input));

        foreach ($triggers as $trigger) {
            $kw = trim(mb_strtolower(substr($trigger->entry_trigger, 8)));
            if ($kw !== '' && str_contains($lower, $kw)) {
                return $trigger;
            }
        }

        return null;
    }

    private function saveCursor(BotSession $session, int $nodeId, ?int $currentGraph): void
    {
        if ($currentGraph === null) {
            $session->update(['current_node_id' => $nodeId]);
            $session->mergeData(['__cursor' => null]);
        } else {
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
        // I trigger 'scheduler:*' sono solo per invocazione programmatica
        // (es. SendBookingReminders), mai matchati da messaggi utente.
        if (str_starts_with($t, 'scheduler:')) return false;
        if ($t === 'first_message') return true;
        if (str_starts_with($t, 'keyword:')) {
            $kw = trim(mb_strtolower(substr($t, 8)));
            return str_contains(mb_strtolower($input), $kw);
        }
        return false;
    }

    /* ───────────────────── Sessione & utente ───────────────────── */

    private function resolveSession(string $channel, string $externalId): BotSession
    {
        $attrs = [
            'state' => 'NEW',
            'data'  => [
                'persona' => \App\Services\Bot\BotPersona::pickRandom(),
                'history' => [],
            ],
        ];
        if ($channel === 'whatsapp') {
            $attrs['phone'] = $externalId;
        }
        return BotSession::firstOrCreate(
            ['channel' => $channel, 'external_id' => $externalId],
            $attrs,
        );
    }

    /**
     * L'identificazione utente è specifica del canale. Per WhatsApp cerchiamo
     * per `phone`; per altri canali non c'è (ancora) un concetto di utente
     * tennistico autenticato, quindi ritorniamo null. I moduli dominio che
     * hanno bisogno di un User gestiranno il caso null.
     */
    private function resolveUser(string $channel, string $externalId, BotSession $session): ?User
    {
        if ($channel === 'whatsapp') {
            return User::where('phone', $externalId)->first();
        }
        return null;
    }

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

    private function dispatch(string $channel, string $externalId, array $queue): void
    {
        if (empty($queue)) return;

        $adapter = $this->channels->get($channel);
        if ($adapter === null) {
            Log::warning('FlowRunner: no adapter for channel', ['channel' => $channel]);
            return;
        }

        foreach ($queue as $msg) {
            try {
                $type = $msg['type'] ?? 'text';
                if ($type === 'buttons' && !empty($msg['buttons'])) {
                    $adapter->sendButtons($externalId, (string) ($msg['text'] ?? ''), (array) $msg['buttons']);
                } elseif ($type === 'list' && !empty($msg['items'])) {
                    $adapter->sendList(
                        $externalId,
                        (string) ($msg['text'] ?? ''),
                        (string) ($msg['button'] ?? 'Opzioni'),
                        (array) $msg['items'],
                    );
                } else {
                    $adapter->sendText($externalId, (string) ($msg['text'] ?? ''));
                }
            } catch (\Throwable $e) {
                Log::warning('FlowRunner: dispatch failed', [
                    'channel' => $channel,
                    'error'   => $e->getMessage(),
                ]);
            }
        }
    }
}
