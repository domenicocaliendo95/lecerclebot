<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BotMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BotMessageController extends Controller
{
    /**
     * Lista tutti i messaggi, raggruppati per categoria.
     */
    public function index(): JsonResponse
    {
        $messages = BotMessage::orderBy('category')
            ->orderBy('key')
            ->get()
            ->groupBy('category');

        return response()->json($messages);
    }

    /**
     * Crea un nuovo messaggio (usato dal flow editor inline).
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'key'         => ['required', 'string', 'max:100', 'regex:/^[a-z][a-z0-9_]*$/',
                              Rule::unique('bot_messages', 'key')],
            'text'        => 'required|string|max:1000',
            'category'    => 'nullable|string|max:50',
            'description' => 'nullable|string|max:255',
        ]);

        $message = BotMessage::create([
            'key'         => $validated['key'],
            'text'        => $validated['text'],
            'category'    => $validated['category'] ?? 'custom',
            'description' => $validated['description'] ?? null,
        ]);

        return response()->json($message, 201);
    }

    /**
     * Aggiorna il testo (e opzionalmente description) di un messaggio.
     */
    public function update(Request $request, string $key): JsonResponse
    {
        $message = BotMessage::find($key);

        if (!$message) {
            return response()->json(['message' => 'Messaggio non trovato.'], 404);
        }

        $validated = $request->validate([
            'text'        => 'sometimes|required|string|max:1000',
            'description' => 'nullable|string|max:255',
        ]);

        $message->update($validated);

        return response()->json($message);
    }
}
