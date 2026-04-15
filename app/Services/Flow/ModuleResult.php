<?php

namespace App\Services\Flow;

/**
 * Risultato di Module::execute(), letto dal FlowRunner.
 *
 * - `next`: porta di uscita da seguire per scegliere l'edge. Default "out".
 * - `wait`: se true, il runner si ferma qui (salva cursore e aspetta messaggio).
 * - `data`: merge in session.data (per moduli che scrivono risultati: parse_date,
 *    check_calendar, ecc.).
 * - `send`: messaggi da spedire via WhatsApp dopo il commit. Ogni entry è
 *    ['type' => 'text'|'buttons', 'text' => ..., 'buttons' => [...]].
 */
class ModuleResult
{
    public function __construct(
        public string $next = 'out',
        public bool   $wait = false,
        public array  $data = [],
        public array  $send = [],
    ) {}

    public static function next(string $port = 'out'): self
    {
        return new self(next: $port);
    }

    public static function wait(array $send = [], array $data = []): self
    {
        return new self(wait: true, data: $data, send: $send);
    }

    public function withData(array $data): self
    {
        $this->data = array_merge($this->data, $data);
        return $this;
    }

    public function withSend(array $message): self
    {
        $this->send[] = $message;
        return $this;
    }
}
