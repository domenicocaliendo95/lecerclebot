<?php

namespace App\Services\Flow;

/**
 * Risultato di Module::execute(), letto dal FlowRunner.
 *
 * - `next`: porta di uscita da seguire per scegliere l'edge. Default "out".
 * - `wait`: se true, il runner si ferma qui (salva cursore e aspetta messaggio).
 * - `data`: merge in session.data (per moduli che scrivono risultati: parse_date,
 *    check_calendar, ecc.).
 * - `send`: messaggi da spedire via WhatsApp dopo il commit.
 * - `descendCompositeId`: se settato, il runner entra nel sotto-grafo del
 *    composite indicato. Usato dal CompositeRefModule (istanziato automaticamente
 *    dal registry quando un nodo ha module_key = "composite:<key>").
 * - `ascendPort`: se settato, il runner esce dal composito corrente e prosegue
 *    dal nodo parent emettendo questa porta. Usato da CompositeOutputModule.
 */
class ModuleResult
{
    public function __construct(
        public string  $next = 'out',
        public bool    $wait = false,
        public array   $data = [],
        public array   $send = [],
        public ?int    $descendCompositeId = null,
        public ?string $ascendPort = null,
    ) {}

    public static function next(string $port = 'out'): self
    {
        return new self(next: $port);
    }

    public static function wait(array $send = [], array $data = []): self
    {
        return new self(wait: true, data: $data, send: $send);
    }

    public static function descend(int $compositeId): self
    {
        return new self(descendCompositeId: $compositeId);
    }

    public static function ascend(string $port = 'out'): self
    {
        return new self(ascendPort: $port);
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
