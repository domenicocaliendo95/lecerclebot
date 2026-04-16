<?php

namespace App\Services\Channel;

/**
 * Contratto di un canale (WhatsApp, Webchat, Telegram, App, ecc.).
 *
 * Il FlowRunner è agnostico al canale: chiama questi metodi via ChannelRegistry
 * per spedire messaggi in uscita. Ogni adapter traduce al protocollo specifico
 * del suo canale.
 *
 * Gli inbound sono gestiti da controller dedicati per canale (es.
 * WhatsAppController, WebchatController) che poi chiamano
 * FlowRunner::process($channel, $externalId, $input).
 */
interface ChannelAdapter
{
    /**
     * Chiave univoca del canale. Usata per matchare adapter da ChannelRegistry
     * e per popolare BotSession.channel.
     */
    public function key(): string;

    public function sendText(string $externalId, string $text): void;

    /**
     * Bottoni rapidi. I canali che non supportano bottoni nativi possono
     * ricadere su testo (es. "1. Opzione A\n2. Opzione B").
     *
     * @param string[] $buttons  Etichette dei bottoni.
     */
    public function sendButtons(string $externalId, string $text, array $buttons): void;

    /**
     * Lista scrollabile di opzioni (più di 3 bottoni).
     *
     * @param string[] $items
     */
    public function sendList(string $externalId, string $text, string $buttonLabel, array $items): void;
}
