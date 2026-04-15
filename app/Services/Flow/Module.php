<?php

namespace App\Services\Flow;

/**
 * Contratto di un modulo del flusso.
 *
 * Un Module è una funzione atomica configurabile: dichiara cosa prende in input
 * (config + sessione), cosa emette in output (una porta tra quelle disponibili)
 * e come si esegue. L'editor visuale legge `meta()` e `outputs()` per disegnare
 * card e porte; il FlowRunner chiama `execute()` al passaggio del cursore.
 *
 * Convenzioni:
 *  - Le porte sono stringhe locali ("out", "si", "no", "match", ...).
 *  - Una porta speciale "wait" non esce dal modulo: segnala al runner di
 *    fermarsi e attendere un nuovo messaggio dall'utente.
 *  - La config è un dict libero salvato sul nodo e passato al costruttore.
 */
abstract class Module
{
    public function __construct(protected array $config = [])
    {
    }

    /**
     * Metadati statici visibili nell'editor: key, label, category, description,
     * config schema (campi configurabili dall'utente).
     */
    abstract public function meta(): ModuleMeta;

    /**
     * Porte di output disponibili su questo modulo, tenendo conto della config
     * (es. un "invia_bottoni" espone una porta per ogni bottone configurato).
     *
     * @return array<string,string>  map porta => label umana
     */
    public function outputs(): array
    {
        return ['out' => 'Continua'];
    }

    /**
     * Esegue il modulo. Deve restituire un ModuleResult che indichi:
     *  - la porta di uscita da seguire ('out', 'si', 'no', ecc.)
     *  - eventualmente `wait=true` per mettere in pausa il grafo
     *  - eventualmente messaggi da spedire (invia_* lo fanno)
     *  - eventualmente merge di session.data
     */
    abstract public function execute(FlowContext $ctx): ModuleResult;

    /**
     * Helper: legge un campo della config, con supporto a dot notation.
     */
    protected function cfg(string $key, mixed $default = null): mixed
    {
        return data_get($this->config, $key, $default);
    }
}
