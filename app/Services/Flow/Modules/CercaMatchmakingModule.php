<?php

namespace App\Services\Flow\Modules;

use App\Models\Booking;
use App\Models\MatchInvitation;
use App\Models\User;
use App\Services\Channel\ChannelRegistry;
use App\Services\Flow\FlowContext;
use App\Services\Flow\Module;
use App\Services\Flow\ModuleMeta;
use App\Services\Flow\ModuleResult;
use Illuminate\Support\Facades\Log;

/**
 * Cerca un avversario per matchmaking basato su ELO e invia un invito.
 *
 * Strategia di ricerca a 3 livelli:
 *   1. ELO ± 100
 *   2. ELO ± 200
 *   3. ELO ± 400
 *
 * Se trovato:
 *   - Crea Booking (pending_match) senza evento calendar (creato alla conferma)
 *   - Crea MatchInvitation (pending)
 *   - Invia messaggio WhatsApp all'avversario con [Accetta / Rifiuta]
 *   - Salva contesto nell'avversario per gestione risposta
 *
 * Porte: trovato (invito mandato), nessuno (nessun match), errore.
 */
class CercaMatchmakingModule extends Module
{
    public function meta(): ModuleMeta
    {
        return new ModuleMeta(
            key: 'cerca_matchmaking',
            label: 'Cerca avversario (matchmaking)',
            category: 'azione',
            description: 'Cerca un avversario con ELO simile, crea prenotazione pending e invia invito WhatsApp.',
            icon: 'users',
        );
    }

    public function outputs(): array
    {
        return [
            'trovato' => 'Avversario trovato, invito inviato',
            'nessuno' => 'Nessun avversario disponibile',
            'errore'  => 'Errore',
        ];
    }

    public function execute(FlowContext $ctx): ModuleResult
    {
        $user = $ctx->user;
        if (!$user) {
            return ModuleResult::next('errore');
        }

        $date     = $ctx->get('requested_date');
        $time     = $ctx->get('requested_time');
        $friendly = $ctx->get('requested_friendly') ?? "{$date} {$time}";
        $duration = (int) ($ctx->get('requested_duration_minutes') ?? 60);

        if (empty($date) || empty($time)) {
            return ModuleResult::next('errore');
        }

        try {
            $elo = $user->elo_rating ?? 1200;

            // Ricerca a 3 livelli
            $opponent = null;
            $eloGap   = 0;

            foreach ([100, 200, 400] as $range) {
                $candidate = User::where('id', '!=', $user->id)
                    ->where('is_admin', false)
                    ->whereNotNull('phone')
                    ->whereBetween('elo_rating', [$elo - $range, $elo + $range])
                    ->inRandomOrder()
                    ->first();

                if ($candidate) {
                    $opponent = $candidate;
                    $eloGap   = abs($candidate->elo_rating - $elo);
                    break;
                }
            }

            if (!$opponent) {
                return ModuleResult::next('nessuno');
            }

            // Crea booking pending_match
            $startDT = \Carbon\Carbon::parse("{$date} {$time}", 'Europe/Rome');
            $endDT   = $startDT->copy()->addMinutes($duration);
            $price   = \App\Models\PricingRule::getPriceForSlot($startDT, $duration);

            $booking = Booking::create([
                'player1_id'   => $user->id,
                'player2_id'   => $opponent->id,
                'booking_date' => $startDT->format('Y-m-d'),
                'start_time'   => $startDT->format('H:i:s'),
                'end_time'     => $endDT->format('H:i:s'),
                'price'        => $price,
                'is_peak'      => $startDT->hour >= 18,
                'status'       => 'pending_match',
            ]);

            // Crea invito
            MatchInvitation::create([
                'booking_id'  => $booking->id,
                'receiver_id' => $opponent->id,
                'status'      => 'pending',
            ]);

            // Invia messaggio all'avversario
            $adapter = app(ChannelRegistry::class)->get('whatsapp');
            if ($adapter && $opponent->phone) {
                $msg = "Ciao {$opponent->name}! {$user->name} ti sfida per una partita {$friendly} 🎾\nELO: {$user->elo_rating} (gap: {$eloGap})";
                $adapter->sendButtons($opponent->phone, $msg, ['Accetta', 'Rifiuta']);

                // Logga nella history dell'avversario
                $oppSession = \App\Models\BotSession::where('channel', 'whatsapp')
                    ->where('external_id', $opponent->phone)->first();
                $oppSession?->appendHistory('bot', $msg);
            }

            return ModuleResult::next('trovato')->withData([
                'matchmaking_booking_id'  => $booking->id,
                'matchmaking_opponent'    => $opponent->name,
                'matchmaking_elo_gap'     => $eloGap,
            ]);
        } catch (\Throwable $e) {
            Log::error('cerca_matchmaking failed', ['error' => $e->getMessage()]);
            return ModuleResult::next('errore');
        }
    }
}
