<?php

namespace App\Services\Flow\Modules;

use App\Services\Bot\UserProfileService;
use App\Services\Flow\FlowContext;
use App\Services\Flow\Module;
use App\Services\Flow\ModuleMeta;
use App\Services\Flow\ModuleResult;
use Illuminate\Support\Facades\Log;

/**
 * Persiste il profilo raccolto in sessione sulla tabella users.
 *
 * Legge session.data.profile e lo passa a UserProfileService::saveFromBot(),
 * che crea o aggiorna il record User, calcola l'ELO iniziale e applica i
 * default (persona, elo_rating, ecc.).
 */
class SalvaProfiloModule extends Module
{
    public function meta(): ModuleMeta
    {
        return new ModuleMeta(
            key: 'salva_profilo',
            label: 'Salva profilo utente',
            category: 'dati',
            description: 'Persiste quanto raccolto in session.data.profile sulla tabella users (crea o aggiorna). Emette "ok" o "errore".',
            icon: 'save',
        );
    }

    public function outputs(): array
    {
        return [
            'ok'      => 'Salvato',
            'errore'  => 'Errore',
        ];
    }

    public function execute(FlowContext $ctx): ModuleResult
    {
        $profile = $ctx->get('profile', []);
        if (empty($profile) || !is_array($profile)) {
            Log::warning('salva_profilo: profile vuoto', ['phone' => $ctx->phone]);
            return ModuleResult::next('errore');
        }

        try {
            app(UserProfileService::class)->saveFromBot($ctx->phone, $profile);
            return ModuleResult::next('ok');
        } catch (\Throwable $e) {
            Log::error('salva_profilo failed', [
                'phone' => $ctx->phone,
                'error' => $e->getMessage(),
            ]);
            return ModuleResult::next('errore');
        }
    }
}
