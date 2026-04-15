<?php

namespace App\Services\Flow\Modules;

use App\Services\Flow\FlowContext;
use App\Services\Flow\Module;
use App\Services\Flow\ModuleMeta;
use App\Services\Flow\ModuleResult;

/**
 * Mette in pausa il flusso fino al prossimo messaggio dell'utente.
 *
 * Alla prima entrata: wait=true. Al resume: salva l'input in session.data
 * sotto la chiave configurata (default "last_input_text") e continua sulla
 * porta "out". Utile per raccogliere testo libero (nome, età, descrizione…)
 * che verrà poi validato da un modulo successivo.
 */
class AttendiInputModule extends Module
{
    public function meta(): ModuleMeta
    {
        return new ModuleMeta(
            key: 'attendi_input',
            label: 'Attendi testo utente',
            category: 'attesa',
            description: 'Ferma il flusso finché l\'utente non risponde. Salva la risposta in session.data sotto la chiave scelta.',
            configSchema: [
                'save_to' => [
                    'type'     => 'string',
                    'label'    => 'Chiave dove salvare',
                    'default'  => 'user_reply',
                    'required' => true,
                ],
            ],
            icon: 'pause',
        );
    }

    public function execute(FlowContext $ctx): ModuleResult
    {
        if ($ctx->resuming) {
            $key = (string) $this->cfg('save_to', 'user_reply');
            return ModuleResult::next()->withData([$key => $ctx->input]);
        }

        return ModuleResult::wait();
    }
}
