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
        $this->apiUrl = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent";
    }

    public function chat(string $systemPrompt, array $history, string $userMessage): string
    {
        $contents = [];

        // Aggiungi la history della conversazione
        foreach ($history as $turn) {
            $contents[] = [
                'role' => $turn['role'],
                'parts' => [['text' => $turn['content']]]
            ];
        }

        // Aggiungi il messaggio corrente
        $contents[] = [
            'role' => 'user',
            'parts' => [['text' => $userMessage]]
        ];

        try {
            $response = Http::post($this->apiUrl . '?key=' . $this->apiKey, [
                'system_instruction' => [
                    'parts' => [['text' => $systemPrompt]]
                ],
                'contents' => $contents,
                'generationConfig' => [
                    'temperature' => 0.3,
                    'maxOutputTokens' => 500,
                ],
            ]);

            if (!$response->successful()) {
                Log::error('Gemini error', ['response' => $response->json()]);
                return "Mi dispiace, si è verificato un errore. Riprova tra poco.";
            }

            return data_get($response->json(), 'candidates.0.content.parts.0.text',
                "Non ho capito. Puoi ripetere?");

        } catch (\Exception $e) {
            Log::error('Gemini exception', ['message' => $e->getMessage()]);
            return "Mi dispiace, si è verificato un errore. Riprova tra poco.";
        }
    }
}
