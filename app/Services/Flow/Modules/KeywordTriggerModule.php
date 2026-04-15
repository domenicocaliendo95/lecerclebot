<?php

namespace App\Services\Flow\Modules;

use App\Services\Flow\FlowContext;
use App\Services\Flow\Module;
use App\Services\Flow\ModuleMeta;
use App\Services\Flow\ModuleResult;

/**
 * Trigger basato su parola chiave.
 *
 * Funziona in coppia con il campo `entry_trigger` del nodo:
 *   - is_entry = true
 *   - entry_trigger = "keyword:prenotazioni"
 *
 * Il FlowRunner fa il matching sull'input (substring case-insensitive) prima
 * di entrare nel modulo; qui è un semplice passthrough.
 */
class KeywordTriggerModule extends Module
{
    public function meta(): ModuleMeta
    {
        return new ModuleMeta(
            key: 'trigger_keyword',
            label: 'Trigger parola chiave',
            category: 'trigger',
            description: 'Entra qui quando l\'utente scrive una parola chiave (es. "menu", "prenotazioni"). Impostala sul campo Trigger del nodo.',
            configSchema: [
                'keyword' => [
                    'type'     => 'string',
                    'label'    => 'Parola chiave',
                    'required' => true,
                    'help'     => 'Match case-insensitive, substring. Es. "menu", "prenotazioni", "profilo".',
                ],
            ],
            icon: 'zap',
        );
    }

    public function execute(FlowContext $ctx): ModuleResult
    {
        return ModuleResult::next();
    }
}
