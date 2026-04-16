<?php

namespace App\Services\Channel\Adapters;

use App\Services\Channel\ChannelAdapter;
use Illuminate\Support\Facades\DB;

/**
 * Adapter Webchat: bufferizza i messaggi in uscita nella tabella
 * `webchat_outbox` così un client HTTP/JS può recuperarli via polling
 * (GET /api/webchat/poll?session=...).
 *
 * Questa è l'implementazione più semplice che prova la modularità del
 * sistema: un secondo canale funzionante senza Meta/Telegram API.
 *
 * `external_id` qui è un ID di sessione generato dal client (es. UUID
 * in localStorage). Nessun profilo utente associato di default.
 */
class WebchatAdapter implements ChannelAdapter
{
    public function key(): string
    {
        return 'webchat';
    }

    public function sendText(string $externalId, string $text): void
    {
        $this->enqueue($externalId, ['type' => 'text', 'text' => $text]);
    }

    public function sendButtons(string $externalId, string $text, array $buttons): void
    {
        $this->enqueue($externalId, ['type' => 'buttons', 'text' => $text, 'buttons' => array_values($buttons)]);
    }

    public function sendList(string $externalId, string $text, string $buttonLabel, array $items): void
    {
        $this->enqueue($externalId, ['type' => 'list', 'text' => $text, 'button' => $buttonLabel, 'items' => array_values($items)]);
    }

    private function enqueue(string $externalId, array $payload): void
    {
        DB::table('webchat_outbox')->insert([
            'external_id' => $externalId,
            'payload'     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }
}
