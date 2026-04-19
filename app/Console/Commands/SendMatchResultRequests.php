<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\BotSession;
use App\Models\FlowNode;
use App\Models\MatchResult;
use App\Services\Channel\ChannelRegistry;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Invia richiesta di inserimento risultato 1h dopo la fine della partita.
 *
 * Usa il flusso "Chiedi risultato" (entry_trigger=scheduler:result_request)
 * per mandare il messaggio e gestire la risposta dell'utente — stessa
 * meccanica dei reminder: legge testo+bottoni dal nodo, manda via adapter,
 * setta il cursore sulla sessione.
 */
class SendMatchResultRequests extends Command
{
    protected $signature   = 'bot:send-result-requests
                              {--dry-run : Mostra senza inviare}';
    protected $description = 'Invia richiesta risultato ai giocatori 1h dopo la fine della partita';

    public function handle(ChannelRegistry $channels): int
    {
        $isDry   = $this->option('dry-run');
        $adapter = $channels->get('whatsapp');

        // Trova il nodo entry del flusso post-partita (unificato risultato+feedback)
        $resultNode = FlowNode::where('entry_trigger', 'scheduler:post_match')->first();
        if (!$resultNode) {
            // Fallback al vecchio trigger
            $resultNode = FlowNode::where('entry_trigger', 'scheduler:result_request')->first();
        }
        if (!$resultNode) {
            $this->warn('Nodo flusso post-partita non trovato. Esegui la migrazione.');
            return self::FAILURE;
        }

        $config  = $resultNode->config ?? [];
        $text    = (string) ($config['text'] ?? "Com'è andata la partita?");
        $buttons = collect($config['buttons'] ?? [])
            ->map(fn($b) => is_array($b) ? (string) ($b['label'] ?? '') : (string) $b)
            ->filter(fn($l) => $l !== '')
            ->values()->all();

        $bookings = Booking::where('status', 'confirmed')
            ->where(function ($q) {
                $q->whereNotNull('player2_id')
                  ->orWhereNotNull('player2_name_text');
            })
            ->whereNull('result_requested_at')
            ->whereRaw("ADDTIME(CONCAT(booking_date, ' ', end_time), '01:00:00') <= NOW()")
            ->with(['player1', 'player2'])
            ->get();

        if ($bookings->isEmpty()) {
            $this->info('Nessuna partita da processare.');
            return self::SUCCESS;
        }

        $this->info("Trovate {$bookings->count()} partita/e.");

        foreach ($bookings as $booking) {
            $player1 = $booking->player1;
            $player2 = $booking->player2;
            if (!$player1) continue;

            $dateStr = Carbon::parse($booking->booking_date)->format('d/m');
            $timeStr = mb_substr($booking->start_time, 0, 5);
            $slot    = "{$dateStr} alle {$timeStr}";
            $isTracked = $player2 !== null && $booking->player2_confirmed_at !== null;

            if ($isDry) {
                $this->line("  [DRY] Booking #{$booking->id} — {$slot}");
                continue;
            }

            try {
                if ($isTracked) {
                    MatchResult::firstOrCreate(
                        ['booking_id' => $booking->id],
                        ['winner_id' => null, 'score' => null, 'player1_confirmed' => false, 'player2_confirmed' => false],
                    );
                }

                $players = array_filter([
                    $player1->phone ? $player1 : null,
                    $player2 && $player2->phone ? $player2 : null,
                ]);

                foreach ($players as $player) {
                    $msg = str_replace('{slot}', $slot, $text);

                    if ($adapter && !empty($buttons)) {
                        $adapter->sendButtons($player->phone, $msg, $buttons);
                    } elseif ($adapter) {
                        $adapter->sendText($player->phone, $msg);
                    }

                    // Setta cursore per gestire la risposta via FlowRunner
                    $this->setCursor($player->phone, $booking->id, $resultNode->id);

                    // Log nella history della sessione
                    $session = BotSession::where('channel', 'whatsapp')
                        ->where('external_id', $player->phone)->first();
                    $session?->appendHistory('bot', $msg);
                }

                $booking->update(['result_requested_at' => now()]);

                Log::info('SendMatchResultRequests: inviata', [
                    'booking_id' => $booking->id,
                    'tracked'    => $isTracked,
                ]);
            } catch (\Throwable $e) {
                $this->error("    ✗ Booking #{$booking->id}: {$e->getMessage()}");
                Log::error('SendMatchResultRequests: errore', [
                    'booking_id' => $booking->id,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        return self::SUCCESS;
    }

    private function setCursor(string $phone, int $bookingId, int $nodeId): void
    {
        $session = BotSession::where('channel', 'whatsapp')
            ->where('external_id', $phone)
            ->first();

        if (!$session) {
            $session = BotSession::create([
                'phone' => $phone, 'channel' => 'whatsapp', 'external_id' => $phone,
                'state' => 'NEW', 'data' => [],
            ]);
        }

        if ($session->current_node_id !== null || !empty($session->getData('__cursor'))) {
            return;
        }

        $session->update(['current_node_id' => $nodeId]);
        $session->mergeData([
            'result_booking_id' => $bookingId,
            'selected_booking_id' => $bookingId,
        ]);
    }
}
