<?php

namespace App\Services\Flow\Modules;

use App\Services\Flow\FlowContext;
use App\Services\Flow\Module;
use App\Services\Flow\ModuleMeta;
use App\Services\Flow\ModuleResult;

/**
 * Branch: l'utente è registrato?
 *
 * "Registrato" = esiste un record User col telefono AND (opzionale) profilo
 * onboarding completato. Emette su "si" o "no".
 */
class UtenteRegistratoModule extends Module
{
    public function meta(): ModuleMeta
    {
        return new ModuleMeta(
            key: 'utente_registrato',
            label: 'Utente registrato?',
            category: 'logica',
            description: 'Controlla se chi scrive è già registrato. Porta "sì" se sì, porta "no" se è un nuovo utente.',
            configSchema: [
                'richiedi_onboarding_completo' => [
                    'type'    => 'bool',
                    'label'   => 'Richiedi onboarding completo',
                    'default' => true,
                    'help'    => 'Se attivo, considera "non registrato" anche chi ha un record User ma non ha completato l\'onboarding (manca età, classifica, slot).',
                ],
            ],
            icon: 'user-check',
        );
    }

    public function outputs(): array
    {
        return [
            'si' => 'Sì, registrato',
            'no' => 'No, nuovo utente',
        ];
    }

    public function execute(FlowContext $ctx): ModuleResult
    {
        $user = $ctx->user;
        if ($user === null) {
            return ModuleResult::next('no');
        }

        if ($this->cfg('richiedi_onboarding_completo', true)) {
            $complete = !empty($user->age) && !empty($user->preferred_slots)
                && (!empty($user->self_level) || !empty($user->fit_rating));
            if (!$complete) {
                return ModuleResult::next('no');
            }
        }

        return ModuleResult::next('si');
    }
}
