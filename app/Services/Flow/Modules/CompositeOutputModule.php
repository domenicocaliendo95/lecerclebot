<?php

namespace App\Services\Flow\Modules;

use App\Services\Flow\FlowContext;
use App\Services\Flow\Module;
use App\Services\Flow\ModuleMeta;
use App\Services\Flow\ModuleResult;

/**
 * Nodo di uscita all'interno di un sotto-grafo (composito).
 *
 * Quando l'esecuzione lo raggiunge, il runner "risale" al grafo parent e
 * continua dal nodo che aveva invocato il composito, seguendo l'edge
 * corrispondente alla porta configurata qui (es. "ok", "errore", "indietro").
 *
 * Ogni `composite_output` dentro un composito contribuisce all'elenco delle
 * porte di uscita del modulo virtuale (lette dal ModuleRegistry).
 */
class CompositeOutputModule extends Module
{
    public function meta(): ModuleMeta
    {
        return new ModuleMeta(
            key: 'composite_output',
            label: 'Uscita composito',
            category: 'composito',
            description: 'Punto di uscita di un sotto-grafo. La porta configurata qui diventa una delle porte di output del modulo composito nel grafo principale.',
            configSchema: [
                'port' => [
                    'type'     => 'string',
                    'label'    => 'Nome della porta',
                    'required' => true,
                    'default'  => 'out',
                    'help'     => 'Es. "ok", "errore", "indietro". Diventa visibile come porta di uscita del composito nel grafo principale.',
                ],
                'label' => [
                    'type'    => 'string',
                    'label'   => 'Etichetta (opz.)',
                    'help'    => 'Mostrata nel composito al posto del nome della porta.',
                ],
            ],
            icon: 'log-out',
        );
    }

    public function outputs(): array
    {
        return []; // è un terminale: nessuna porta di uscita nel sotto-grafo
    }

    public function execute(FlowContext $ctx): ModuleResult
    {
        $port = (string) ($this->cfg('port', 'out') ?: 'out');
        return ModuleResult::ascend($port);
    }
}
