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

class SendMatchResultRequests extends Command
{
    protected $signature = 'bot:send-result-requests {--dry-run}';
    protected $description = 'Invia richiesta risultato ai giocatori 1h dopo la fine della partita';

    public function handle(ChannelRegistry $channels): int
    {
        $isDry = $this->option('dry-run');

        Log::info('🏆 bot:send-result-requests START', ['dry' => $isDry]);

        $resultNode = FlowNode::where('entry_trigger', 'scheduler:post_match')->first()
            ?? FlowNode::where('entry_trigger', 'scheduler:result_request')->first();

        if (!$resultNode) {
            Log::error('🏆 Nodo post_match NON TROVATO');
            $this->warn('Nodo flusso post-partita non trovato.');
            return self::FAILURE;
        }

        $config  = $resultNode->config ?? [];
        $text    = (string) ($config['text'] ?? "Com'è andata la partita?");
        $buttons = collect($config['buttons'] ?? [])
            ->map(fn($b) => is_array($b) ? (string) ($b['label'] ?? '') : (string) $b)
            ->filter(fn($l) => $l !== '')
            ->values()->all();

        $adapter = $channels->get('whatsapp');
        if (!$adapter) {
            Log::error('🏆 WhatsApp adapter NON TROVATO');
            return self::FAILURE;
        }

        $bookings = Booking::where('status', 'confirmed')
            ->where(function ($q) {
                $q->whereNotNull('player2_id')
                  ->orWhereNotNull('player2_name_text');
            })
            ->whereNull('result_requested_at')
            ->whereRaw("ADDTIME(CONCAT(booking_date, ' ', end_time), '01:00:00') <= NOW()")
            ->with(['player1', 'player2'])
            ->get();

        Log::info("🏆 Trovate {$bookings->count()} partite da processare");

        if ($bookings->isEmpty()) {
            $this->info('Nessuna partita da processare.');
            return self::SUCCESS;
        }

        foreach ($bookings as $booking) {
            $player1 = $booking->player1;
            if (!$player1) continue;

            $dateStr = Carbon::parse($booking->booking_date)->format('d/m');
            $timeStr = mb_substr($booking->start_time, 0, 5);
            $slot    = "{$dateStr} alle {$timeStr}";
            $isTracked = $booking->player2_id !== null && $booking->player2_confirmed_at !== null;

            Log::info("🏆 Booking #{$booking->id} — {$slot} — tracked: " . ($isTracked ? 'sì' : 'no'));

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
                    $booking->player2 && $booking->player2->phone ? $booking->player2 : null,
                ]);

                foreach ($players as $player) {
                    $msg = str_replace('{slot}', $slot, $text);

                    if (!empty($buttons)) {
                        $adapter->sendButtons($player->phone, $msg, $buttons);
                    } else {
                        $adapter->sendText($player->phone, $msg);
                    }

                    $this->setCursor($player->phone, $booking->id, $resultNode->id);

                    $session = BotSession::where('channel', 'whatsapp')
                        ->where('external_id', $player->phone)->first();
                    $session?->appendHistory('bot', $msg);

                    Log::info("🏆 ✅ Result request INVIATA", [
                        'booking' => $booking->id,
                        'phone'   => $player->phone,
                    ]);
                }

                $booking->update(['result_requested_at' => now()]);
            } catch (\Throwable $e) {
                Log::error("🏆 ❌ Result request FALLITA", [
                    'booking' => $booking->id,
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        Log::info('🏆 bot:send-result-requests END');
        return self::SUCCESS;
    }

    private function setCursor(string $phone, int $bookingId, int $nodeId): void
    {
        $session = BotSession::where('channel', 'whatsapp')
            ->where('external_id', $phone)->first();

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
            'result_booking_id'   => $bookingId,
            'selected_booking_id' => $bookingId,
        ]);
    }
}
