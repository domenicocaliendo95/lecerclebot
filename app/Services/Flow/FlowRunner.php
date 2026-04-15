<?php

namespace App\Services\Flow;

use App\Models\BotSession;
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
 * che chiede `wait` — tipicamente attendi_input o invia_bottoni.
 *
 * Responsabilità:
 *  - risolvere/creare sessione
 *  - matchare il trigger giusto quando current_node_id è null
 *  - istanziare il modulo corrente, eseguirlo, seguire l'edge dalla porta emessa
 *  - applicare `wait` (stop) / `data` (merge) / `send` (accodato, spedito fuori tx)
 *  - evitare loop infiniti (hard cap)
 */
class FlowRunner
{
    private const MAX_STEPS = 50;

    public function __construct(
        private readonly ModuleRegistry $registry,
        private readonly WhatsAppService $whatsApp,
    ) {}

    /**
     * Punto d'ingresso dal webhook.
     */
    public function process(string $phone, string $input): void
    {
        $queue = $this->run($phone, $input);
        $this->dispatch($phone, $queue);
    }

    /**
     * Versione "dry": esegue il grafo e restituisce i messaggi accodati senza
     * spedirli. Usato dal comando artisan flow:simulate per testare i grafi
     * senza toccare WhatsApp.
     *
     * @return array<int,array>
     */
    public function simulate(string $phone, string $input): array
    {
        return $this->run($phone, $input);
    }

    /**
     * Core: risolve sessione, avvia il walk, ritorna la coda di messaggi.
     */
    private function run(string $phone, string $input): array
    {
        $queue = [];

        try {
            DB::transaction(function () use ($phone, $input, &$queue) {
                $user    = User::where('phone', $phone)->first();
                $session = $this->resolveSession($phone);
                $session->mergeData(['last_input' => $input]);

                $resumingFromCursor = $session->current_node_id !== null;
                $node = $this->resolveStartingNode($session, $input);
                if ($node === null) {
                    Log::warning('FlowRunner: no matching entry node', [
                        'phone'   => $phone,
                        'input'   => $input,
                        'current' => $session->current_node_id,
                    ]);
                    return;
                }

                $queue = $this->walk($node, $session, $phone, $input, $user, $resumingFromCursor);
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

    /* ───────────────────── Core loop ───────────────────── */

    /**
     * Esegue nodi consecutivi fino a incontrare un wait o un nodo terminale.
     *
     * @return array<int,array>  messaggi da spedire (fuori dalla tx)
     */
    private function walk(
        FlowNode   $start,
        BotSession $session,
        string     $phone,
        string     $input,
        ?User      $user,
        bool       $resumingFromCursor,
    ): array {
        $queue = [];
        $node  = $start;
        $currentInput = $input;
        $resuming     = $resumingFromCursor;

        for ($step = 0; $step < self::MAX_STEPS; $step++) {
            $module = $this->registry->instantiate($node->module_key, $node->config ?? []);
            if ($module === null) {
                Log::error('FlowRunner: unknown module', [
                    'node_id'    => $node->id,
                    'module_key' => $node->module_key,
                ]);
                break;
            }

            $ctx = new FlowContext(
                session:  $session,
                phone:    $phone,
                input:    $currentInput,
                user:     $user,
                node:     $node,
                resuming: $resuming,
            );

            try {
                $result = $module->execute($ctx);
            } catch (\Throwable $e) {
                Log::error('FlowRunner: module execute failed', [
                    'node_id'    => $node->id,
                    'module_key' => $node->module_key,
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

            if ($result->wait) {
                // Il nodo attende l'input successivo: salva cursore e ferma.
                $session->update(['current_node_id' => $node->id]);
                return $queue;
            }

            // Segui l'edge dalla porta emessa.
            $next = $this->nextNode($node, $result->next);
            if ($next === null) {
                // Fine del flusso: nessun edge. Azzera cursore.
                $session->update(['current_node_id' => null]);
                return $queue;
            }

            $node         = $next;
            $currentInput = ''; // l'input utente è valido solo per il primo nodo del walk
            $resuming     = false;
        }

        Log::warning('FlowRunner: MAX_STEPS reached', [
            'phone'   => $phone,
            'last'    => $node->id ?? null,
        ]);
        return $queue;
    }

    /**
     * Trova il nodo da cui partire: se la sessione ha un cursore, riprende da lì;
     * altrimenti cerca un trigger d'ingresso che matchi l'input.
     */
    private function resolveStartingNode(BotSession $session, string $input): ?FlowNode
    {
        if ($session->current_node_id) {
            $node = FlowNode::find($session->current_node_id);
            if ($node) {
                return $node;
            }
        }

        // Nessun cursore: cerca un trigger. Per ora il matcher è semplice:
        // esiste sempre un entry `first_message` e poi matcher per keyword.
        // I trigger più specifici (keyword) hanno priorità su first_message.
        $triggers = FlowNode::where('is_entry', true)
            ->orderByRaw("CASE WHEN entry_trigger LIKE 'keyword:%' THEN 0 ELSE 1 END")
            ->get();

        foreach ($triggers as $trigger) {
            if ($this->matchesTrigger($trigger, $input)) {
                return $trigger;
            }
        }

        return null;
    }

    private function matchesTrigger(FlowNode $trigger, string $input): bool
    {
        $t = $trigger->entry_trigger ?? 'first_message';

        if ($t === 'first_message') {
            return true;
        }

        if (str_starts_with($t, 'keyword:')) {
            $kw = trim(mb_strtolower(substr($t, 8)));
            return str_contains(mb_strtolower($input), $kw);
        }

        return false;
    }

    private function nextNode(FlowNode $node, string $port): ?FlowNode
    {
        $edge = FlowEdge::where('from_node_id', $node->id)
            ->where('from_port', $port)
            ->first();

        return $edge ? FlowNode::find($edge->to_node_id) : null;
    }

    /* ───────────────────── Sessione ───────────────────── */

    private function resolveSession(string $phone): BotSession
    {
        return BotSession::firstOrCreate(
            ['phone' => $phone],
            ['state' => 'NEW', 'data' => []],
        );
    }

    /* ───────────────────── Invio messaggi ───────────────────── */

    /**
     * Spedisce la coda di messaggi al numero (fuori transazione).
     */
    private function dispatch(string $phone, array $queue): void
    {
        foreach ($queue as $msg) {
            try {
                $type = $msg['type'] ?? 'text';
                if ($type === 'buttons' && !empty($msg['buttons'])) {
                    $this->whatsApp->sendButtons(
                        $phone,
                        (string) ($msg['text'] ?? ''),
                        (array) $msg['buttons'],
                    );
                } else {
                    $this->whatsApp->sendText($phone, (string) ($msg['text'] ?? ''));
                }
            } catch (\Throwable $e) {
                Log::warning('FlowRunner: dispatch failed', [
                    'phone' => $phone,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
