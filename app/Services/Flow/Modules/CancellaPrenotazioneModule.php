<?php

namespace App\Services\Flow\Modules;

use App\Models\Booking;
use App\Services\CalendarService;
use App\Services\Flow\FlowContext;
use App\Services\Flow\Module;
use App\Services\Flow\ModuleMeta;
use App\Services\Flow\ModuleResult;
use Illuminate\Support\Facades\Log;

/**
 * Cancella la prenotazione selezionata (session.data.selected_booking_id).
 * Rimuove l'evento Calendar e marca la booking come cancelled.
 */
class CancellaPrenotazioneModule extends Module
{
    public function meta(): ModuleMeta
    {
        return new ModuleMeta(
            key: 'cancella_prenotazione',
            label: 'Cancella prenotazione',
            category: 'azione',
            description: 'Cancella la prenotazione indicata da session.data.selected_booking_id (DB + Google Calendar).',
            icon: 'calendar-x',
        );
    }

    public function outputs(): array
    {
        return [
            'ok'     => 'Cancellata',
            'errore' => 'Errore',
        ];
    }

    public function execute(FlowContext $ctx): ModuleResult
    {
        $bookingId = $ctx->get('selected_booking_id');
        if (!$bookingId) {
            return ModuleResult::next('errore');
        }

        try {
            $booking = Booking::find($bookingId);
            if (!$booking) {
                return ModuleResult::next('errore');
            }

            if ($booking->gcal_event_id) {
                try { app(CalendarService::class)->deleteEvent($booking->gcal_event_id); } catch (\Throwable) {}
            }
            $booking->update(['status' => 'cancelled']);

            return ModuleResult::next('ok')->withData(['selected_booking_id' => null]);
        } catch (\Throwable $e) {
            Log::error('cancella_prenotazione failed', ['error' => $e->getMessage()]);
            return ModuleResult::next('errore');
        }
    }
}
