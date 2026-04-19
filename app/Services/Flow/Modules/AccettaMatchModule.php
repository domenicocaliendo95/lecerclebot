<?php

namespace App\Services\Flow\Modules;

use App\Models\Booking;
use App\Models\BotSession;
use App\Models\MatchInvitation;
use App\Services\CalendarService;
use App\Services\Channel\ChannelRegistry;
use App\Services\Flow\FlowContext;
use App\Services\Flow\Module;
use App\Services\Flow\ModuleMeta;
use App\Services\Flow\ModuleResult;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * L'avversario accetta la sfida matchmaking.
 *
 * Legge da session.data: matchmaking_booking_id, matchmaking_challenger_name
 *
 * Azioni:
 *  - Booking → confirmed + player2_confirmed_at = now
 *  - Crea evento Google Calendar
 *  - MatchInvitation → accepted
 *  - Notifica il challenger via WhatsApp
 */
class AccettaMatchModule extends Module
{
    public function meta(): ModuleMeta
    {
        return new ModuleMeta(
            key: 'accetta_match',
            label: 'Accetta sfida matchmaking',
            category: 'azione',
            description: "Conferma il match: crea l'evento calendario, conferma la prenotazione e avvisa lo sfidante.",
            icon: 'check-circle',
        );
    }

    public function outputs(): array
    {
        return ['ok' => 'Confermato', 'errore' => 'Errore'];
    }

    public function execute(FlowContext $ctx): ModuleResult
    {
        $bookingId = $ctx->get('matchmaking_booking_id');
        if (!$bookingId) {
            return ModuleResult::next('errore');
        }

        try {
            $booking = Booking::with(['player1', 'player2'])->find($bookingId);
            if (!$booking || $booking->status !== 'pending_match') {
                return ModuleResult::next('errore');
            }

            $challenger = $booking->player1;
            $opponent   = $booking->player2;

            // Crea evento Calendar
            $startDT = Carbon::parse($booking->booking_date->format('Y-m-d') . ' ' . $booking->start_time, 'Europe/Rome');
            $endDT   = Carbon::parse($booking->booking_date->format('Y-m-d') . ' ' . $booking->end_time, 'Europe/Rome');

            $summary = "Partita - {$challenger->name} vs {$opponent->name}";
            $desc = implode("\n", [
                "Giocatore 1: {$challenger->name} ({$challenger->phone})",
                "Giocatore 2: {$opponent->name} ({$opponent->phone})",
                "Tipo: Matchmaking",
                "Prenotato via: WhatsApp Bot",
            ]);

            $gcalEvent = app(CalendarService::class)->createEvent(
                summary: $summary, description: $desc,
                startTime: $startDT, endTime: $endDT,
            );

            // Conferma booking
            $booking->update([
                'status'               => 'confirmed',
                'gcal_event_id'        => $gcalEvent->getId(),
                'player2_confirmed_at' => now(),
            ]);

            // Aggiorna invito
            MatchInvitation::where('booking_id', $bookingId)
                ->where('status', 'pending')
                ->update(['status' => 'accepted']);

            // Notifica challenger
            $slot = $booking->booking_date->locale('it')->isoFormat('dddd D MMMM')
                . ' alle ' . substr($booking->start_time, 0, 5);

            $adapter = app(ChannelRegistry::class)->get('whatsapp');
            if ($adapter && $challenger->phone) {
                $msg = "{$opponent->name} ha accettato la sfida per {$slot}! 🎾✅ L'evento è stato aggiunto al calendario.";
                $adapter->sendText($challenger->phone, $msg);

                // Log nella history del challenger
                $challSession = BotSession::where('channel', 'whatsapp')
                    ->where('external_id', $challenger->phone)->first();
                $challSession?->appendHistory('bot', $msg);

                // Resetta il cursore del challenger (era in attesa)
                if ($challSession) {
                    $challSession->update(['current_node_id' => null]);
                    $challSession->mergeData(['__cursor' => null, '__flow_stack' => null]);
                }
            }

            return ModuleResult::next('ok')->withData([
                'match_confirmed_slot' => $slot,
            ]);
        } catch (\Throwable $e) {
            Log::error('accetta_match failed', ['error' => $e->getMessage()]);
            return ModuleResult::next('errore');
        }
    }
}
