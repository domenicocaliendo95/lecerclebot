<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiService
{
    private string $apiKey;
    private string $apiUrl;

    public function __construct()
    {
        $this->apiKey = env('GEMINI_KEY');
        // TORNATO ALLA TUA VERSIONE ORIGINALE! (gemini-2.5-flash su v1beta)
        $this->apiUrl = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent";
    }

    public function chat(string $systemPrompt, array $history, string $userMessage): string
    {
        $contents = [];
        $lastRole = null;

        // 1. Costruiamo la history in modo infallibile
        foreach ($history as $turn) {
            $content = $turn['content'] ?? $turn['text'] ?? '';

            // Saltiamo messaggi vuoti e i vecchi messaggi di errore del bot per non confonderlo
            if (empty(trim($content)) || str_contains($content, 'Spiacente') || str_contains($content, 'Mi dispiace') || str_contains($content, 'Errore tecnico')) {
                continue;
            }

            // Normalizziamo in 'model' e 'user'
            $role = (isset($turn['role']) && ($turn['role'] === 'assistant' || $turn['role'] === 'model')) ? 'model' : 'user';

            // GEMINI CRASHA CON RUOLI CONSECUTIVI: Accorpiamo se il ruolo è uguale al precedente
            if ($role === $lastRole) {
                $lastIndex = count($contents) - 1;
                $contents[$lastIndex]['parts'][0]['text'] .= "\n" . $content;
            } else {
                $contents[] = [
                    'role' => $role,
                    'parts' => [['text' => $content]]
                ];
                $lastRole = $role;
            }
        }

        // 2. Aggiunta dell'ultimo messaggio utente (accorpato se anche l'ultimo nella history era 'user')
        if ($lastRole === 'user') {
            $lastIndex = count($contents) - 1;
            $contents[$lastIndex]['parts'][0]['text'] .= "\n" . $userMessage;
        } else {
            $contents[] = [
                'role' => 'user',
                'parts' => [['text' => $userMessage]]
            ];
        }

        try {
            // Sintassi corretta (snake_case) per le API REST di Google
            $payload = [
                'system_instruction' => [
                    'parts' => [['text' => $systemPrompt]]
                ],
                'contents' => $contents,
                'generationConfig' => [
                    'temperature' => 0.7,
                    'maxOutputTokens' => 1000,
                    'response_mime_type' => 'application/json', // Forza l'output JSON
                ],
            ];

            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
            ])->post($this->apiUrl . '?key=' . $this->apiKey, $payload);

            if (!$response->successful()) {
                Log::error('Gemini API Error Detail', [
                    'status' => $response->status(),
                    'payload' => $payload,
                    'error' => $response->json()
                ]);
                return "Spiacente, errore di comunicazione (Code: " . $response->status() . ")";
            }

            $text = data_get($response->json(), 'candidates.0.content.parts.0.text');

            return $text ?? "Errore: Risposta vuota.";

        } catch (\Exception $e) {
            Log::error('Gemini Exception', ['message' => $e->getMessage()]);
            return "Errore imprevisto di connessione.";
        }
    }
}
