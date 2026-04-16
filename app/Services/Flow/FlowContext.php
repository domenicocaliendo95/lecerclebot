<?php

namespace App\Services\Flow;

use App\Models\BotSession;
use App\Models\FlowNode;
use App\Models\User;

/**
 * Stato condiviso tra modulo e runner.
 *
 * Ogni modulo legge/scrive direttamente su `$session->data` via helper, e
 * dispone di `$input` (l'ultimo messaggio utente, stringa vuota se il modulo
 * è stato invocato dall'interno del grafo e non da un messaggio WhatsApp).
 */
class FlowContext
{
    public function __construct(
        public readonly BotSession $session,
        public readonly string     $channel,      // es. 'whatsapp', 'webchat'
        public readonly string     $externalId,   // identificatore dell'utente sul canale
        public readonly string     $input,
        public readonly ?User      $user,
        public readonly FlowNode   $node,
        public readonly bool       $resuming = false,
    ) {}

    /**
     * Backward-compat: molti moduli tennis-specifici (CreaPrenotazione,
     * SalvaProfilo) leggono $ctx->phone. Su WhatsApp è identico a externalId;
     * su altri canali resta vuoto a meno di associazioni esplicite.
     */
    public function __get(string $name): mixed
    {
        if ($name === 'phone') {
            return $this->channel === 'whatsapp' ? $this->externalId : '';
        }
        return null;
    }

    /** Legge un valore da session.data (dot notation). */
    public function get(string $key, mixed $default = null): mixed
    {
        return data_get($this->session->data ?? [], $key, $default);
    }

    /** Scrive/aggiorna chiavi in session.data e salva. */
    public function set(array $data): void
    {
        $this->session->mergeData($data);
    }
}
