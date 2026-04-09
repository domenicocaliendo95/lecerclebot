<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BotMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
     * Aggiorna il testo di un messaggio.
     */
    public function update(Request $request, string $key): JsonResponse
    {
        $message = BotMessage::find($key);

        if (!$message) {
            return response()->json(['message' => 'Messaggio non trovato.'], 404);
        }

        $request->validate([
            'text' => 'required|string|max:1000',
        ]);

        $message->update(['text' => $request->input('text')]);

        return response()->json($message);
    }
}
