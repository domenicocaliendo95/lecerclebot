<?php

namespace App\Services\Flow\Modules;

use App\Models\Booking;
use App\Services\Flow\FlowContext;
use App\Services\Flow\Module;
use App\Services\Flow\ModuleMeta;
use App\Services\Flow\ModuleResult;

/**
 * Carica le prossime prenotazioni dell'utente in session.data.bookings_list.
 *
 * Porte: "trovate" se almeno una esiste, "nessuna" altrimenti.
 */
class CaricaPrenotazioniModule extends Module
{
    public function meta(): ModuleMeta
    {
        return new ModuleMeta(
            key: 'carica_prenotazioni',
            label: 'Carica prenotazioni',
            category: 'dati',
            description: 'Carica le prossime prenotazioni confermate/in attesa dell\'utente in session.data.bookings_list.',
            configSchema: [
                'limit' => [
                    'type'    => 'int',
                    'label'   => 'Max risultati',
                    'default' => 3,
                ],
            ],
            icon: 'list',
        );
    }

    public function outputs(): array
    {
        return [
            'trovate' => 'Trovate',
            'nessuna' => 'Nessuna',
        ];
    }

    public function execute(FlowContext $ctx): ModuleResult
    {
        $user = $ctx->user;
        if (!$user) {
            return ModuleResult::next('nessuna')->withData(['bookings_list' => []]);
        }

        $limit = (int) $this->cfg('limit', 3);

        $list = Booking::where(function ($q) use ($user) {
                $q->where('player1_id', $user->id)
                  ->orWhere('player2_id', $user->id);
            })
            ->whereIn('status', ['confirmed', 'pending_match'])
            ->where('booking_date', '>=', now()->format('Y-m-d'))
            ->orderBy('booking_date')
            ->orderBy('start_time')
            ->limit($limit)
            ->get()
            ->map(fn(Booking $b) => [
                'id'     => $b->id,
                'date'   => $b->booking_date->format('Y-m-d'),
                'time'   => substr($b->start_time, 0, 5),
                'status' => $b->status,
                'label'  => $b->booking_date->locale('it')->isoFormat('ddd D MMM') . ' ' . substr($b->start_time, 0, 5),
            ])
            ->toArray();

        return ModuleResult::next(empty($list) ? 'nessuna' : 'trovate')
            ->withData(['bookings_list' => $list]);
    }
}
