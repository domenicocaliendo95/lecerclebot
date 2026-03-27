<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Client per le API Gemini.
 *
 * Due modalità:
 * - generate(): prompt singolo, per riformulazione testi e parsing date
 * - chat(): conversazione multi-turno (mantenuta per retrocompatibilità)
 */
class GeminiService
{
    private string $apiKey;
    private string $model;
    private string $baseUrl;
    private int    $timeoutSeconds;

    public function __construct()
    {
        $this->apiKey         = config('services.gemini.api_key');
        $this->model          = config('services.gemini.model', 'gemini-2.0-flash');
        $this->baseUrl        = config('services.gemini.base_url', 'https://generativelanguage.googleapis.com/v1beta');
        $this->timeoutSeconds = config('services.gemini.timeout', 15);
    }

    /**
     * Genera una risposta da un prompt singolo.
     * Usato per riformulazione testi e parsing date.
     */
    public function generate(string $prompt): string
    {
        $url = "{$this->baseUrl}/models/{$this->model}:generateContent?key={$this->apiKey}";

        $response = Http::timeout($this->timeoutSeconds)
            ->retry(2, 500, throw: false)
            ->post($url, [
                'contents' => [
                    ['parts' => [['text' => $prompt]]],
                ],
                'generationConfig' => [
                    'temperature'    => 0.7,
                    'maxOutputTokens' => 300,
                ],
            ]);

        if (!$response->successful()) {
            Log::error('Gemini API error', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);

            throw new \RuntimeException("Gemini API error: HTTP {$response->status()}");
        }

        $text = data_get($response->json(), 'candidates.0.content.parts.0.text', '');

        if (empty($text)) {
            throw new \RuntimeException('Gemini returned empty response');
        }

        return $text;
    }

    /**
     * Chat multi-turno con system prompt.
     * Mantenuto per retrocompatibilità.
     */
    public function chat(string $systemPrompt, array $history, string $userMessage): string
    {
        $url = "{$this->baseUrl}/models/{$this->model}:generateContent?key={$this->apiKey}";

        $contents = [];

        // System instruction come primo messaggio
        $contents[] = [
            'role'  => 'user',
            'parts' => [['text' => "[SYSTEM]\n{$systemPrompt}\n[/SYSTEM]"]],
        ];
        $contents[] = [
            'role'  => 'model',
            'parts' => [['text' => 'Capito, seguirò le istruzioni.']],
        ];

        // History
        foreach ($history as $entry) {
            $role = $entry['role'] === 'user' ? 'user' : 'model';
            $contents[] = [
                'role'  => $role,
                'parts' => [['text' => $entry['content']]],
            ];
        }

        // Messaggio corrente
        $contents[] = [
            'role'  => 'user',
            'parts' => [['text' => $userMessage]],
        ];

        $response = Http::timeout($this->timeoutSeconds)
            ->retry(2, 500, throw: false)
            ->post($url, [
                'contents'         => $contents,
                'generationConfig' => [
                    'temperature'     => 0.7,
                    'maxOutputTokens' => 500,
                ],
            ]);

        if (!$response->successful()) {
            throw new \RuntimeException("Gemini API error: HTTP {$response->status()}");
        }

        return data_get($response->json(), 'candidates.0.content.parts.0.text', '');
    }
}
