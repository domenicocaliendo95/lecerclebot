<?php

namespace App\Services\Flow\Modules;

use App\Models\Booking;
use App\Models\BotSession;
use App\Models\MatchInvitation;
use App\Services\Channel\ChannelRegistry;
use App\Services\Flow\FlowContext;
use App\Services\Flow\Module;
use App\Services\Flow\ModuleMeta;
use App\Services\Flow\ModuleResult;
use Illuminate\Support\Facades\Log;

/**
 * L'avversario rifiuta la sfida matchmaking.
 *
 * Azioni:
 *  - Booking → cancelled
 *  - MatchInvitation → refused
 *  - Notifica il challenger via WhatsApp
 */
class RifiutaMatchModule extends Module
{
    public function meta(): ModuleMeta
    {
        return new ModuleMeta(
            key: 'rifiuta_match',
            label: 'Rifiuta sfida matchmaking',
            category: 'azione',
            description: "Rifiuta il match: cancella la prenotazione e avvisa lo sfidante.",
            icon: 'x-circle',
        );
    }

    public function outputs(): array
    {
        return ['ok' => 'Rifiutato', 'errore' => 'Errore'];
    }

    public function execute(FlowContext $ctx): ModuleResult
    {
        $bookingId = $ctx->get('matchmaking_booking_id');
        if (!$bookingId) {
            return ModuleResult::next('errore');
        }

        try {
            $booking = Booking::with(['player1', 'player2'])->find($bookingId);
            if (!$booking) {
                return ModuleResult::next('errore');
            }

            $challenger = $booking->player1;
            $opponent   = $booking->player2;

            // Cancella booking
            if ($booking->gcal_event_id) {
                try { app(\App\Services\CalendarService::class)->deleteEvent($booking->gcal_event_id); } catch (\Throwable) {}
            }
            $booking->update(['status' => 'cancelled']);

            // Aggiorna invito
            MatchInvitation::where('booking_id', $bookingId)
                ->where('status', 'pending')
                ->update(['status' => 'refused']);

            // Notifica challenger
            $slot = $booking->booking_date->locale('it')->isoFormat('dddd D MMMM')
                . ' alle ' . substr($booking->start_time, 0, 5);

            $adapter = app(ChannelRegistry::class)->get('whatsapp');
            if ($adapter && $challenger->phone) {
                $msg = "{$opponent->name} ha rifiutato la sfida per {$slot}. Puoi provare con un altro avversario!";
                $adapter->sendText($challenger->phone, $msg);

                $challSession = BotSession::where('channel', 'whatsapp')
                    ->where('external_id', $challenger->phone)->first();
                $challSession?->appendHistory('bot', $msg);

                if ($challSession) {
                    $challSession->update(['current_node_id' => null]);
                    $challSession->mergeData(['__cursor' => null, '__flow_stack' => null]);
                }
            }

            return ModuleResult::next('ok');
        } catch (\Throwable $e) {
            Log::error('rifiuta_match failed', ['error' => $e->getMessage()]);
            return ModuleResult::next('errore');
        }
    }
}
