<?php

namespace App\Services\Flow\Modules;

use App\Services\CalendarService;
use App\Services\Flow\FlowContext;
use App\Services\Flow\Module;
use App\Services\Flow\ModuleMeta;
use App\Services\Flow\ModuleResult;
use Illuminate\Support\Facades\Log;

/**
 * Verifica su Google Calendar se lo slot richiesto è libero.
 *
 * Legge da session.data.requested_date + requested_time (già popolati dal
 * modulo parse_data). Se libero → porta "libero". Se occupato → porta
 * "occupato", salva le alternative in session.data.calendar_alternatives.
 * Se manca la data → porta "errore".
 */
class VerificaCalendarioModule extends Module
{
    public function meta(): ModuleMeta
    {
        return new ModuleMeta(
            key: 'verifica_calendario',
            label: 'Verifica calendario',
            category: 'dati',
            description: 'Controlla su Google Calendar se lo slot richiesto è libero. Salva alternative in caso sia occupato.',
            configSchema: [
                'durata_minuti' => [
                    'type'    => 'int',
                    'label'   => 'Durata (minuti)',
                    'default' => 60,
                    'help'    => 'Ignorato se session.data.requested_duration_minutes è già impostato.',
                ],
            ],
            icon: 'calendar-check',
        );
    }

    public function outputs(): array
    {
        return [
            'libero'   => 'Libero',
            'occupato' => 'Occupato',
            'errore'   => 'Errore',
        ];
    }

    public function execute(FlowContext $ctx): ModuleResult
    {
        $date = $ctx->get('requested_date');
        $time = $ctx->get('requested_time');

        if (empty($date)) {
            return ModuleResult::next('errore')->withData([
                'calendar_available' => false,
                'calendar_error'     => 'missing_date',
            ]);
        }

        $duration = (int) ($ctx->get('requested_duration_minutes') ?? $this->cfg('durata_minuti', 60));

        try {
            $query  = $time ? "{$date} {$time}" : ($ctx->get('requested_raw') ?? $date);
            $result = app(CalendarService::class)->checkUserRequest($query, $duration);

            $available    = (bool) ($result['available'] ?? false);
            $alternatives = $result['alternatives'] ?? [];

            return ModuleResult::next($available ? 'libero' : 'occupato')->withData([
                'calendar_available'    => $available,
                'calendar_alternatives' => $alternatives,
                'calendar_result'       => $result,
                'calendar_error'        => null,
            ]);
        } catch (\Throwable $e) {
            Log::error('verifica_calendario failed', [
                'date'  => $date,
                'time'  => $time,
                'error' => $e->getMessage(),
            ]);
            return ModuleResult::next('errore')->withData([
                'calendar_available' => false,
                'calendar_error'     => 'api_error',
            ]);
        }
    }
}
