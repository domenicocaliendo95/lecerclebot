<?php

namespace App\Services\Channel\Adapters;

use App\Services\Channel\ChannelAdapter;
use App\Services\WhatsAppService;

/**
 * Adapter WhatsApp: delega al WhatsAppService preesistente (Meta Cloud API).
 *
 * `external_id` qui è il numero di telefono nel formato E.164 (es. +393331234567),
 * così come Meta lo fornisce nel webhook.
 */
class WhatsAppAdapter implements ChannelAdapter
{
    public function __construct(private readonly WhatsAppService $whatsApp) {}

    public function key(): string
    {
        return 'whatsapp';
    }

    public function sendText(string $externalId, string $text): void
    {
        $this->whatsApp->sendText($externalId, $text);
    }

    public function sendButtons(string $externalId, string $text, array $buttons): void
    {
        $this->whatsApp->sendButtons($externalId, $text, $buttons);
    }

    public function sendList(string $externalId, string $text, string $buttonLabel, array $items): void
    {
        $this->whatsApp->sendList($externalId, $text, $buttonLabel, $items);
    }
}
