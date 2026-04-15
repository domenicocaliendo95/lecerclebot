<?php

namespace App\Services\Flow\Modules;

use App\Services\Flow\FlowContext;
use App\Services\Flow\Module;
use App\Services\Flow\ModuleMeta;
use App\Services\Flow\ModuleResult;

/**
 * Trigger: primo messaggio ricevuto dal numero.
 *
 * Passa il controllo al nodo successivo senza fare nulla: è solo un marker
 * visuale di ingresso. Il matching `entry_trigger=first_message` è gestito
 * dal FlowRunner in resolveStartingNode.
 */
class PrimoMessaggioModule extends Module
{
    public function meta(): ModuleMeta
    {
        return new ModuleMeta(
            key: 'primo_messaggio',
            label: 'Messaggio ricevuto',
            category: 'trigger',
            description: 'Entra qui ogni volta che un utente scrive al bot senza una sessione in corso. Punto di ingresso principale del flusso.',
            icon: 'message-square',
        );
    }

    public function execute(FlowContext $ctx): ModuleResult
    {
        return ModuleResult::next();
    }
}
