<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BotFlowState;
use App\Models\BotMessage;
use App\Services\Bot\BotState;
use App\Services\Bot\StateHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class BotFlowStateController extends Controller
{
    /**
     * Lista tutti gli stati del flusso, raggruppati per categoria.
     * Include il testo del messaggio associato.
     */
    public function index(): JsonResponse
    {
        $states   = BotFlowState::orderBy('sort_order')->get();
        $messages = BotMessage::pluck('text', 'key')->toArray();

        $enriched = $states->map(function ($state) use ($messages) {
            $data = $state->toArray();
            $data['message_text']  = $messages[$state->message_key] ?? null;
            $data['fallback_text'] = $state->fallback_key ? ($messages[$state->fallback_key] ?? null) : null;
            return $data;
        });

        return response()->json($enriched->groupBy('category'));
    }

    /**
     * Endpoint dedicato al flow editor visuale.
     * Restituisce nodes (con position) + edges (button-driven editabili e code-driven read-only).
     */
    public function graph(): JsonResponse
    {
        $states   = BotFlowState::orderBy('sort_order')->get();
        $messages = BotMessage::pluck('text', 'key')->toArray();

        // ── Nodi ──────────────────────────────────────────────────
        $nodes = $states->map(function (BotFlowState $s) use ($messages) {
            return [
                'id'           => $s->state,
                'state'        => $s->state,
                'type'         => $s->type,
                'is_custom'    => (bool) $s->is_custom,
                'category'     => $s->category,
                'description'  => $s->description,
                'message_key'  => $s->message_key,
                'message_text' => $messages[$s->message_key] ?? null,
                'fallback_key' => $s->fallback_key,
                'fallback_text'=> $s->fallback_key ? ($messages[$s->fallback_key] ?? null) : null,
                'buttons'      => $s->buttons ?? [],
                'position'     => $s->position,
                'sort_order'   => $s->sort_order,
            ];
        })->values();

        // ── Edge button-driven (estratti dai buttons di ogni stato) ──
        $buttonEdges = [];
        foreach ($states as $s) {
            foreach (($s->buttons ?? []) as $idx => $btn) {
                $buttonEdges[] = [
                    'id'     => "btn-{$s->state}-{$idx}",
                    'source' => $s->state,
                    'target' => $btn['target_state'] ?? null,
                    'label'  => $btn['label'] ?? '',
                    'kind'   => 'button',
                    'side_effect' => $btn['side_effect'] ?? null,
                    'editable' => $s->type === 'simple' || $s->is_custom,
                ];
            }
        }

        // ── Edge code-driven (BotState::allowedTransitions, read-only) ──
        // Prendiamo solo le transizioni del codice che NON sono già coperte da bottoni
        $codeEdges = [];
        $existingTargets = [];
        foreach ($buttonEdges as $e) {
            $existingTargets["{$e['source']}->{$e['target']}"] = true;
        }

        foreach (BotState::cases() as $case) {
            foreach ($case->allowedTransitions() as $target) {
                if ($target === $case) {
                    continue; // self-loop = re-prompt, lo nascondiamo nel graph
                }
                $key = "{$case->value}->{$target->value}";
                if (isset($existingTargets[$key])) {
                    continue;
                }
                $codeEdges[] = [
                    'id'       => "code-{$case->value}-{$target->value}",
                    'source'   => $case->value,
                    'target'   => $target->value,
                    'label'    => null,
                    'kind'     => 'code',
                    'side_effect' => null,
                    'editable' => false,
                ];
            }
        }

        return response()->json([
            'nodes'       => $nodes,
            'buttonEdges' => $buttonEdges,
            'codeEdges'   => $codeEdges,
        ]);
    }

    /**
     * Restituisce i metadati utili al frontend per popolare i dropdown del flow editor.
     *  - whitelist side_effect disponibili
     *  - lista bot_messages (per scegliere message_key/fallback_key)
     *  - lista stati built-in (case dell'enum BotState)
     *  - lista categorie note
     */
    public function meta(): JsonResponse
    {
        $messages = BotMessage::orderBy('category')->orderBy('key')
            ->get(['key', 'category', 'description'])
            ->toArray();

        $builtIn = array_map(fn(BotState $c) => $c->value, BotState::cases());

        $categories = ['onboarding', 'menu', 'prenotazione', 'conferma', 'matchmaking',
                       'gestione', 'profilo', 'risultati', 'feedback', 'avversario',
                       'errore', 'custom'];

        return response()->json([
            'side_effects' => StateHandler::availableSideEffects(),
            'messages'     => $messages,
            'built_in'     => $builtIn,
            'categories'   => $categories,
        ]);
    }

    /**
     * Crea un nuovo stato custom (solo type=simple).
     * Forza is_custom=true e type=simple. Lo state-name deve essere uppercase A-Z, _ e cifre.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'state'        => ['required', 'string', 'max:30', 'regex:/^[A-Z][A-Z0-9_]*$/',
                               Rule::unique('bot_flow_states', 'state')],
            'message_key'  => 'required|string|exists:bot_messages,key',
            'fallback_key' => 'nullable|string|exists:bot_messages,key',
            'category'     => 'nullable|string|max:50',
            'description'  => 'nullable|string|max:255',
            'buttons'      => 'nullable|array|max:3',
            'position'     => 'nullable|array',
            'position.x'   => 'nullable|numeric',
            'position.y'   => 'nullable|numeric',
        ]);

        // Built-in non si possono creare/sovrascrivere come custom
        if (BotState::tryFrom($validated['state']) !== null) {
            return response()->json([
                'message' => 'Questo nome è riservato a uno stato built-in del codice.',
            ], 422);
        }

        // Validazione bottoni
        if (!empty($validated['buttons'])) {
            $error = $this->validateButtons($validated['buttons'], $validated['state']);
            if ($error) {
                return response()->json(['message' => $error], 422);
            }
        }

        $state = BotFlowState::create([
            'state'        => $validated['state'],
            'type'         => 'simple',                      // Custom = sempre simple
            'is_custom'    => true,
            'message_key'  => $validated['message_key'],
            'fallback_key' => $validated['fallback_key'] ?? null,
            'category'     => $validated['category'] ?? 'custom',
            'description'  => $validated['description'] ?? null,
            'buttons'      => $validated['buttons'] ?? [],
            'position'     => $validated['position'] ?? null,
            'sort_order'   => (BotFlowState::max('sort_order') ?? 0) + 1,
        ]);

        return response()->json($this->enrich($state), 201);
    }

    /**
     * Aggiorna uno stato del flusso (built-in o custom).
     * I built-in possono solo aggiornare bottoni/messaggi/descrizione.
     */
    public function update(Request $request, string $state): JsonResponse
    {
        $flowState = BotFlowState::find($state);

        if (!$flowState) {
            return response()->json(['message' => 'Stato non trovato.'], 404);
        }

        $validated = $request->validate([
            'message_key'  => 'sometimes|string|exists:bot_messages,key',
            'fallback_key' => 'nullable|string|exists:bot_messages,key',
            'description'  => 'nullable|string|max:255',
            'category'     => 'nullable|string|max:50',
            'buttons'      => 'nullable|array|max:3',
            'position'     => 'nullable|array',
            'position.x'   => 'nullable|numeric',
            'position.y'   => 'nullable|numeric',
        ]);

        // Validazione bottoni
        if (array_key_exists('buttons', $validated) && !empty($validated['buttons'])) {
            $error = $this->validateButtons($validated['buttons'], $state);
            if ($error) {
                return response()->json(['message' => $error], 422);
            }
        }

        // Per stati built-in: non permettere di cambiare la categoria
        if (!$flowState->is_custom && array_key_exists('category', $validated)) {
            unset($validated['category']);
        }

        $flowState->update($validated);

        return response()->json($this->enrich($flowState->fresh()));
    }

    /**
     * Bulk save delle posizioni dopo drag&drop nel flow editor.
     * Body: [{state: "MENU", position: {x: 100, y: 200}}, ...]
     */
    public function savePositions(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'positions'              => 'required|array',
            'positions.*.state'      => 'required|string|exists:bot_flow_states,state',
            'positions.*.position'   => 'required|array',
            'positions.*.position.x' => 'required|numeric',
            'positions.*.position.y' => 'required|numeric',
        ]);

        DB::transaction(function () use ($validated) {
            foreach ($validated['positions'] as $row) {
                BotFlowState::where('state', $row['state'])
                    ->update(['position' => $row['position']]);
            }
        });

        BotFlowState::clearCache();

        return response()->json(['message' => 'Posizioni salvate.', 'count' => count($validated['positions'])]);
    }

    /**
     * Elimina uno stato custom.
     * Built-in non eliminabili. Custom eliminabili solo se nessun altro stato li referenzia.
     */
    public function destroy(string $state): JsonResponse
    {
        $flowState = BotFlowState::find($state);

        if (!$flowState) {
            return response()->json(['message' => 'Stato non trovato.'], 404);
        }

        if (!$flowState->is_custom) {
            return response()->json([
                'message' => 'Gli stati built-in non possono essere eliminati dal pannello.',
            ], 422);
        }

        // Cerca stati che lo referenziano nei loro bottoni
        $referencingStates = BotFlowState::where('state', '!=', $state)
            ->get()
            ->filter(function (BotFlowState $s) use ($state) {
                foreach (($s->buttons ?? []) as $btn) {
                    if (($btn['target_state'] ?? null) === $state) {
                        return true;
                    }
                }
                return false;
            })
            ->pluck('state')
            ->toArray();

        if (!empty($referencingStates)) {
            return response()->json([
                'message' => 'Impossibile eliminare: lo stato è referenziato da: '
                             . implode(', ', $referencingStates),
                'referenced_by' => $referencingStates,
            ], 422);
        }

        $flowState->delete();

        return response()->json(['message' => 'Stato eliminato.']);
    }

    /* ───────── Helpers ───────── */

    /**
     * Valida un array di bottoni:
     *  - max 3
     *  - label max 20 char, non vuota
     *  - target_state esiste (in enum O nel DB)
     *  - side_effect (se presente) è nella whitelist
     */
    private function validateButtons(array $buttons, string $sourceState): ?string
    {
        if (count($buttons) > 3) {
            return 'Massimo 3 bottoni per stato.';
        }

        $allowedSideEffects = array_keys(StateHandler::availableSideEffects());
        $allTargets         = BotFlowState::pluck('state')->toArray();
        $allBuiltIn         = array_map(fn(BotState $c) => $c->value, BotState::cases());

        foreach ($buttons as $idx => $btn) {
            $label  = $btn['label'] ?? '';
            $target = $btn['target_state'] ?? '';

            if (trim($label) === '') {
                return "Bottone #" . ($idx + 1) . ": label vuota.";
            }
            if (mb_strlen($label) > 20) {
                return "Bottone #" . ($idx + 1) . ": label oltre 20 caratteri.";
            }
            if (trim($target) === '') {
                return "Bottone #" . ($idx + 1) . ": target_state mancante.";
            }
            if (!in_array($target, $allTargets, true) && !in_array($target, $allBuiltIn, true)) {
                return "Bottone #" . ($idx + 1) . ": target_state '{$target}' non esiste.";
            }
            if (!empty($btn['side_effect']) && !in_array($btn['side_effect'], $allowedSideEffects, true)) {
                return "Bottone #" . ($idx + 1) . ": side_effect '{$btn['side_effect']}' non valido.";
            }
        }

        return null;
    }

    private function enrich(BotFlowState $state): array
    {
        $messages = BotMessage::pluck('text', 'key')->toArray();
        $data = $state->toArray();
        $data['message_text']  = $messages[$state->message_key] ?? null;
        $data['fallback_text'] = $state->fallback_key ? ($messages[$state->fallback_key] ?? null) : null;
        return $data;
    }
}
