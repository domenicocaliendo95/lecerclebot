<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\BotSession;
use App\Models\BotSetting;
use App\Models\FlowNode;
use App\Services\Channel\ChannelRegistry;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Invia richiesta feedback ai giocatori dopo la partita.
 *
 * Scatta X ore dopo che il risultato è stato richiesto (result_requested_at),
 * così arriva DOPO il reminder risultato. Usa lo stesso pattern dei reminder:
 * legge testo/bottoni dal nodo del flusso, manda via adapter, setta cursore.
 *
 * Dedup: usa bookings.feedback_requested_at (colonna da aggiungere se mancante,
 * altrimenti usa il campo reminders_sent con chiave 'feedback').
 */
class SendFeedbackRequests extends Command
{
    protected $signature = 'bot:send-feedback-requests {--dry-run}';
    protected $description = 'Invia richiesta feedback ai giocatori dopo la partita';

    public function handle(ChannelRegistry $channels): int
    {
        // Con il flusso post-partita unificato, il feedback è incluso
        // nella stessa conversazione del risultato. Questo comando è
        // mantenuto per backward compat ma potrebbe non essere necessario.
        $config = BotSetting::get('post_match', []);
        // Supporta sia formato vecchio {feedback_request:{...}} che nuovo flat
        $fbConfig = $config['feedback_request'] ?? $config;

        if (!($fbConfig['enabled'] ?? false) || empty($fbConfig['flow_node_id'])) {
            $this->info('Feedback request disabilitato.');
            return self::SUCCESS;
        }

        $hoursAfter = (int) ($fbConfig['hours_after'] ?? 3);
        $nodeId     = (int) $fbConfig['flow_node_id'];
        $isDry      = $this->option('dry-run');
        $adapter    = $channels->get('whatsapp');

        $flowNode = FlowNode::find($nodeId);
        if (!$flowNode) {
            $this->warn("Nodo flusso feedback non trovato (id={$nodeId}).");
            return self::FAILURE;
        }

        $config  = $flowNode->config ?? [];
        $text    = (string) ($config['text'] ?? "Com'è stata l'esperienza?");
        $buttons = collect($config['buttons'] ?? [])
            ->map(fn($b) => is_array($b) ? (string) ($b['label'] ?? '') : (string) $b)
            ->filter(fn($l) => $l !== '')
            ->values()->all();

        $now = Carbon::now('Europe/Rome');

        // Trova prenotazioni dove il risultato è stato richiesto X ore fa
        // e il feedback NON è ancora stato richiesto
        $bookings = Booking::whereNotNull('result_requested_at')
            ->whereRaw("result_requested_at <= ?", [$now->copy()->subHours($hoursAfter)->format('Y-m-d H:i:s')])
            ->where(function ($q) {
                // Dedup: controlla che 'feedback' non sia già in reminders_sent
                $q->whereNull('reminders_sent')
                  ->orWhereRaw("JSON_EXTRACT(reminders_sent, '$.feedback') IS NULL");
            })
            ->with(['player1', 'player2'])
            ->get();

        if ($bookings->isEmpty()) {
            $this->info('Nessun feedback da richiedere.');
            return self::SUCCESS;
        }

        $sent = 0;

        foreach ($bookings as $booking) {
            $dateStr = Carbon::parse($booking->booking_date)->format('d/m');
            $timeStr = mb_substr($booking->start_time, 0, 5);
            $slot    = "{$dateStr} alle {$timeStr}";

            $players = array_filter([
                $booking->player1 && $booking->player1->phone ? $booking->player1 : null,
                $booking->player2 && $booking->player2->phone ? $booking->player2 : null,
            ]);

            if (empty($players)) continue;

            // Dry-run: log e basta, niente claim, niente invio
            if ($isDry) {
                foreach ($players as $player) {
                    $msg = str_replace(['{name}', '{slot}'], [$player->name, $slot], $text);
                    $this->line("  [DRY] {$player->phone}: {$msg}");
                }
                $sent++;
                continue;
            }

            // Claim atomico dello slot feedback: UPDATE...WHERE non ancora marcato.
            // Evita duplicati se due processi scheduler girano in parallelo.
            if (!$this->claimFeedbackSlot($booking->id)) {
                Log::info("⭐ Skip feedback #{$booking->id}: già claimato da altro processo");
                continue;
            }

            foreach ($players as $player) {
                $msg = str_replace(['{name}', '{slot}'], [$player->name, $slot], $text);

                try {
                    if ($adapter && !empty($buttons)) {
                        $adapter->sendButtons($player->phone, $msg, $buttons);
                    } elseif ($adapter) {
                        $adapter->sendText($player->phone, $msg);
                    }

                    $this->setCursor($player->phone, $booking->id, $nodeId);

                    $session = BotSession::where('channel', 'whatsapp')
                        ->where('external_id', $player->phone)->first();
                    $session?->appendHistory('bot', $msg);
                } catch (\Throwable $e) {
                    Log::warning('Feedback request send failed', [
                        'phone' => $player->phone,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $sent++;
        }

        if ($sent > 0 && !$isDry) {
            Log::info("⭐ Feedback richiesti: {$sent}");
        }
        $this->info(($isDry ? '[DRY] ' : '') . "Feedback richiesti: {$sent}");
        return self::SUCCESS;
    }

    /**
     * Marca atomicamente reminders_sent.feedback come inviato.
     * Ritorna true solo se la riga è stata aggiornata in questo tick.
     */
    private function claimFeedbackSlot(int $bookingId): bool
    {
        $path = '$."feedback"';
        $ts   = now()->toIso8601String();

        $affected = DB::update(
            "UPDATE bookings
             SET reminders_sent = JSON_SET(COALESCE(reminders_sent, JSON_OBJECT()), ?, ?),
                 updated_at = NOW()
             WHERE id = ?
               AND (reminders_sent IS NULL OR JSON_EXTRACT(reminders_sent, ?) IS NULL)",
            [$path, $ts, $bookingId, $path]
        );

        return $affected > 0;
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
        $session->mergeData(['result_booking_id' => $bookingId]);
    }
}
