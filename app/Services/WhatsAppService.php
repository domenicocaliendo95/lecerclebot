<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppService
{
    private string $token;
    private string $phoneNumberId;
    private string $apiUrl;

    public function __construct()
    {
        $this->token         = env('WHATSAPP_TOKEN');
        $this->phoneNumberId = env('WHATSAPP_PHONE_NUMBER_ID');
        $this->apiUrl        = "https://graph.facebook.com/v21.0/{$this->phoneNumberId}/messages";
    }

    /**
     * Invia un messaggio di testo semplice
     */
    public function sendText(string $to, string $message): void
    {
        $this->send($to, [
            'type' => 'text',
            'text' => ['body' => $message],
        ]);
    }

    /**
     * Invia un messaggio con pulsanti Quick Reply (max 3)
     */
    public function sendButtons(string $to, string $message, array $buttons): void
    {
        $buttonList = collect($buttons)->map(fn($label, $i) => [
            'type'  => 'reply',
            'reply' => [
                'id'    => 'btn_' . $i,
                'title' => mb_substr($label, 0, 20),
            ],
        ])->values()->all();

        $this->send($to, [
            'type'        => 'interactive',
            'interactive' => [
                'type' => 'button',
                'body' => ['text' => $message],
                'action' => ['buttons' => $buttonList],
            ],
        ]);
    }

    /**
     * Invia un messaggio con lista (max 10 opzioni)
     */
    public function sendList(string $to, string $message, string $buttonLabel, array $items): void
    {
        $rows = collect($items)->map(fn($item, $i) => [
            'id'    => 'item_' . $i,
            'title' => mb_substr($item, 0, 24),
        ])->values()->all();

        $this->send($to, [
            'type'        => 'interactive',
            'interactive' => [
                'type'   => 'list',
                'body'   => ['text' => $message],
                'action' => [
                    'button'   => $buttonLabel,
                    'sections' => [[
                        'title' => 'Opzioni',
                        'rows'  => $rows,
                    ]],
                ],
            ],
        ]);
    }

    /**
     * Metodo base — chiamato da tutti gli altri
     */
    private function send(string $to, array $payload): void
    {
        $body = array_merge([
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $to,
        ], $payload);

        try {
            $response = Http::withToken($this->token)
                ->post($this->apiUrl, $body);

            if (!$response->successful()) {
                Log::error('WhatsApp send error', [
                    'to'       => $to,
                    'status'   => $response->status(),
                    'response' => $response->json(),
                ]);
            }
        } catch (\Exception $e) {
            Log::error('WhatsApp exception', ['message' => $e->getMessage()]);
        }
    }
}
