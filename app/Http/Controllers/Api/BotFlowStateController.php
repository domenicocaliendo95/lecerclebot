<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BotFlowState;
use App\Models\BotMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BotFlowStateController extends Controller
{
    /**
     * Lista tutti gli stati del flusso, raggruppati per categoria.
     * Include il testo del messaggio associato.
     */
    public function index(): JsonResponse
    {
        $states = BotFlowState::orderBy('sort_order')->get();

        // Arricchisci con il testo del messaggio
        $messages = BotMessage::pluck('text', 'key')->toArray();

        $enriched = $states->map(function ($state) use ($messages) {
            $data = $state->toArray();
            $data['message_text'] = $messages[$state->message_key] ?? null;
            $data['fallback_text'] = $state->fallback_key ? ($messages[$state->fallback_key] ?? null) : null;
            return $data;
        });

        $grouped = $enriched->groupBy('category');

        return response()->json($grouped);
    }

    /**
     * Aggiorna uno stato del flusso (bottoni, message_key, ecc).
     */
    public function update(Request $request, string $state): JsonResponse
    {
        $flowState = BotFlowState::find($state);

        if (!$flowState) {
            return response()->json(['message' => 'Stato non trovato.'], 404);
        }

        $validated = $request->validate([
            'buttons'      => 'nullable|array|max:3',
            'buttons.*.label'        => 'required|string|max:20',
            'buttons.*.target_state' => 'required|string|max:30',
            'buttons.*.value'        => 'nullable|string|max:50',
            'buttons.*.side_effect'  => 'nullable|string|max:50',
            'message_key'  => 'sometimes|string|max:100',
            'fallback_key' => 'nullable|string|max:100',
            'description'  => 'nullable|string|max:255',
        ]);

        $flowState->update($validated);

        // Arricchisci risposta con testi
        $messages = BotMessage::pluck('text', 'key')->toArray();
        $data = $flowState->toArray();
        $data['message_text'] = $messages[$flowState->message_key] ?? null;
        $data['fallback_text'] = $flowState->fallback_key ? ($messages[$flowState->fallback_key] ?? null) : null;

        return response()->json($data);
    }
}
