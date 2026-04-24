<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\BotSession;
use App\Models\BotSetting;
use App\Models\FlowNode;
use App\Services\Channel\ChannelRegistry;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Cron unico per i promemoria prenotazioni — gira ogni 5 minuti.
 *
 * Per ogni slot attivo in bot_settings.reminders:
 *   1. Trova le prenotazioni nella finestra temporale (±8 min)
 *   2. Controlla se il reminder per quello slot è già stato inviato
 *      (campo bookings.reminders_sent JSON, persistente e affidabile)
 *   3. Se non inviato: legge testo + bottoni dal nodo del flusso,
 *      manda via ChannelAdapter, setta il cursore del FlowRunner
 *      sulla sessione, marca come inviato su DB
 */
class SendBookingReminders extends Command
{
    protected $signature = 'bot:send-reminders
                           {--dry-run : Mostra senza inviare}';

    protected $description = 'Invia promemoria prenotazioni (dedup su DB, gira ogni 5 min)';

    public function handle(ChannelRegistry $channels): int
    {
        $settings = BotSetting::get('reminders', ['enabled' => true, 'slots' => []]);

        if (!($settings['enabled'] ?? true)) {
            $this->info('Reminders disabilitati.');
            return self::SUCCESS;
        }

        $slots = collect($settings['slots'] ?? [])
            ->filter(fn($s) => ($s['enabled'] ?? false) && !empty($s['flow_node_id']))
            ->values();

        if ($slots->isEmpty()) {
            $this->info('Nessuno slot reminder attivo.');
            return self::SUCCESS;
        }

        $now     = Carbon::now('Europe/Rome');
        $sent    = 0;
        $isDry   = $this->option('dry-run');
        $adapter = $channels->get('whatsapp');

        foreach ($slots as $slot) {
            $hoursBefore = (int) ($slot['hours_before'] ?? 0);
            $slotKey     = (string) $hoursBefore;
            $nodeId      = (int) $slot['flow_node_id'];

            $flowNode = FlowNode::find($nodeId);
            if (!$flowNode) {
                $this->warn("Slot {$hoursBefore}h: nodo {$nodeId} non trovato.");
                continue;
            }

            $config  = $flowNode->config ?? [];
            $text    = (string) ($config['text'] ?? 'Promemoria prenotazione');
            $buttons = collect($config['buttons'] ?? [])
                ->map(fn($b) => is_array($b) ? (string) ($b['label'] ?? '') : (string) $b)
                ->filter(fn($l) => $l !== '')
                ->values()
                ->all();

            // Query robusta: manda se la partita è entro hours_before
            // E il reminder non è ancora stato inviato.
            // Finestra larga per non perdere MAI un reminder:
            //   - Max: partita entro hours_before + 10min da ora
            //   - Min: per slot >=6h → catch-up fino a hours_before/2
            //         per slot <6h → manda finché la partita non è iniziata
            $deadlineMax = $now->copy()->addHours($hoursBefore)->addMinutes(10);
            $minHours = $hoursBefore >= 6 ? (int) ($hoursBefore / 2) : 0;
            $deadlineMin = $now->copy()->addHours($minHours);

            $bookings = Booking::whereIn('status', ['confirmed', 'pending_match'])
                ->whereNotNull('player1_id')
                ->whereRaw(
                    "CONCAT(booking_date, ' ', start_time) BETWEEN ? AND ?",
                    [$deadlineMin->format('Y-m-d H:i:s'), $deadlineMax->format('Y-m-d H:i:s')]
                )
                ->with(['player1', 'player2'])
                ->get();

            foreach ($bookings as $booking) {
                // Dedup su DB: controlla se già inviato per questo slot
                $alreadySent = $booking->reminders_sent[$slotKey] ?? false;
                if ($alreadySent) continue;

                $slotLabel = Carbon::parse($booking->booking_date)
                    ->locale('it')->isoFormat('dddd D MMMM')
                    . ' alle ' . substr($booking->start_time, 0, 5);

                $players = array_filter([
                    $booking->player1 && $booking->player1->phone ? $booking->player1 : null,
                    $booking->player2 && $booking->player2->phone ? $booking->player2 : null,
                ]);

                if (empty($players)) continue;

                if ($isDry) {
                    foreach ($players as $player) {
                        $msg = str_replace(['{name}', '{slot}', '{hours}'], [$player->name, $slotLabel, (string) $hoursBefore], $text);
                        $this->line("  [DRY] {$slotKey}h · {$player->phone}: {$msg}");
                    }
                    $sent++;
                    continue; // DRY: NON marcare come inviato!
                }

                foreach ($players as $player) {
                    $msg = str_replace(
                        ['{name}', '{slot}', '{hours}'],
                        [$player->name, $slotLabel, (string) $hoursBefore],
                        $text
                    );

                    try {
                        if ($adapter && !empty($buttons)) {
                            $adapter->sendButtons($player->phone, $msg, $buttons);
                        } elseif ($adapter) {
                            $adapter->sendText($player->phone, $msg);
                        }

                        if (!empty($buttons)) {
                            $this->setCursorForResponse($player->phone, $booking->id, $nodeId);
                        }

                        $this->logToHistory($player->phone, $msg);

                        Log::info('Reminder inviato', [
                            'booking'      => $booking->id,
                            'phone'        => $player->phone,
                            'hours_before' => $hoursBefore,
                        ]);
                    } catch (\Throwable $e) {
                        Log::warning('Reminder send failed', [
                            'phone' => $player->phone,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                // Marca come inviato su DB SOLO se non dry-run
                $existing = $booking->reminders_sent ?? [];
                $existing[$slotKey] = now()->toIso8601String();
                $booking->update(['reminders_sent' => $existing]);
                $sent++;
            }
        }

        $this->info(($isDry ? '[DRY] ' : '') . "Prenotazioni notificate: {$sent}");
        return self::SUCCESS;
    }

    private function logToHistory(string $phone, string $message): void
    {
        $session = BotSession::where('channel', 'whatsapp')
            ->where('external_id', $phone)
            ->first();
        $session?->appendHistory('bot', $message);
    }

    private function setCursorForResponse(string $phone, int $bookingId, int $nodeId): void
    {
        $session = BotSession::where('channel', 'whatsapp')
            ->where('external_id', $phone)
            ->first();

        if (!$session) {
            $session = BotSession::create([
                'phone'       => $phone,
                'channel'     => 'whatsapp',
                'external_id' => $phone,
                'state'       => 'NEW',
                'data'        => [],
            ]);
        }

        if ($session->current_node_id !== null || !empty($session->getData('__cursor'))) {
            return;
        }

        $session->update(['current_node_id' => $nodeId]);
        $session->mergeData(['selected_booking_id' => $bookingId]);
    }
}
