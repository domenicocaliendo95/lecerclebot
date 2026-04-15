<?php

namespace App\Services\Flow\Modules;

use App\Services\Flow\FlowContext;
use App\Services\Flow\Module;
use App\Services\Flow\ModuleMeta;
use App\Services\Flow\ModuleResult;

/**
 * Termina il flusso: azzera il cursore della sessione e ferma il runner.
 *
 * Utile come nodo finale esplicito nell'editor, per evitare che gli archi
 * restino "aperti". Il FlowRunner già resetta il cursore quando non trova
 * un edge successivo, ma questo modulo rende l'intento visibile.
 */
class FineFlussoModule extends Module
{
    public function meta(): ModuleMeta
    {
        return new ModuleMeta(
            key: 'fine_flusso',
            label: 'Fine flusso',
            category: 'attesa',
            description: 'Termina il flusso corrente. La prossima volta che l\'utente scrive, il runner cercherà un nuovo trigger.',
            icon: 'flag',
        );
    }

    public function outputs(): array
    {
        return [];
    }

    public function execute(FlowContext $ctx): ModuleResult
    {
        // Marker: nessun edge in uscita → il runner resetta current_node_id.
        return ModuleResult::next('end');
    }
}
