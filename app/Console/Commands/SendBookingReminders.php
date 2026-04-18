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
 * Invia promemoria per le prenotazioni imminenti.
 *
 * Ogni reminder slot in bot_settings.reminders punta a un flow_node_id —
 * un nodo `invia_bottoni` nel grafo. Il comando:
 *   1. Carica il nodo e ne legge testo + bottoni dal config
 *   2. Interpola le variabili ({name}, {slot}, {hours})
 *   3. Manda il messaggio via ChannelAdapter (WhatsApp di default)
 *   4. Setta session.data.selected_booking_id + current_node_id così che
 *      quando l'utente clicca un bottone (es. "Disdici"), il FlowRunner
 *      riprende dal nodo e segue l'edge corrispondente
 *
 * Schedulato ogni 15 minuti via routes/console.php.
 */
class SendBookingReminders extends Command
{
    protected $signature = 'bot:send-reminders
                           {--dry-run : Mostra senza inviare}';

    protected $description = 'Invia promemoria prenotazioni con messaggio e bottoni dal flusso configurato';

    public function handle(ChannelRegistry $channels): int
    {
        $settings = BotSetting::get('reminders', [
            'enabled' => true,
            'slots'   => [],
        ]);

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

        $now   = Carbon::now('Europe/Rome');
        $sent  = 0;
        $isDry = $this->option('dry-run');
        $adapter = $channels->get('whatsapp');

        foreach ($slots as $slot) {
            $hoursBefore = (int) ($slot['hours_before'] ?? 0);
            $nodeId      = (int) $slot['flow_node_id'];

            $flowNode = FlowNode::find($nodeId);
            if (!$flowNode) {
                $this->warn("Slot {$hoursBefore}h: flow_node_id={$nodeId} non trovato, skip.");
                continue;
            }

            $config  = $flowNode->config ?? [];
            $text    = (string) ($config['text'] ?? 'Promemoria prenotazione');
            $buttons = array_map(
                fn($b) => is_array($b) ? (string) ($b['label'] ?? '') : (string) $b,
                (array) ($config['buttons'] ?? [])
            );
            $buttons = array_filter($buttons, fn($l) => $l !== '');

            $windowStart = $now->copy()->addHours($hoursBefore)->subMinutes(7);
            $windowEnd   = $now->copy()->addHours($hoursBefore)->addMinutes(8);

            $bookings = Booking::whereIn('status', ['confirmed', 'pending_match'])
                ->whereNotNull('player1_id')
                ->whereRaw(
                    "CONCAT(booking_date, ' ', start_time) BETWEEN ? AND ?",
                    [$windowStart->format('Y-m-d H:i:s'), $windowEnd->format('Y-m-d H:i:s')]
                )
                ->with(['player1', 'player2'])
                ->get();

            foreach ($bookings as $booking) {
                $cacheKey = "reminder:{$booking->id}:{$hoursBefore}h";
                if (cache()->has($cacheKey)) continue;

                $slotLabel = Carbon::parse($booking->booking_date)->locale('it')->isoFormat('dddd D MMMM')
                    . ' alle ' . substr($booking->start_time, 0, 5);

                $players = array_filter([
                    $booking->player1 && $booking->player1->phone ? $booking->player1 : null,
                    $booking->player2 && $booking->player2->phone ? $booking->player2 : null,
                ]);

                foreach ($players as $player) {
                    $msg = $this->interpolate($text, $player->name, $slotLabel, $hoursBefore);

                    if ($isDry) {
                        $this->line("  [DRY] {$player->phone}: {$msg}");
                        $sent++;
                        continue;
                    }

                    try {
                        if ($adapter && !empty($buttons)) {
                            $adapter->sendButtons($player->phone, $msg, $buttons);
                        } elseif ($adapter) {
                            $adapter->sendText($player->phone, $msg);
                        }

                        // Setta il cursore sul nodo reminder così il FlowRunner gestirà
                        // la risposta dell'utente (es. click su "Disdici")
                        if (!empty($buttons)) {
                            $this->setCursorForResponse($player->phone, $booking->id, $nodeId);
                        }

                        $sent++;
                    } catch (\Throwable $e) {
                        Log::warning('Reminder send failed', [
                            'phone' => $player->phone,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                cache()->put($cacheKey, true, now()->addHours(48));
            }
        }

        $this->info(($isDry ? '[DRY] ' : '') . "Reminders inviati: {$sent}");
        return self::SUCCESS;
    }

    private function interpolate(string $template, string $name, string $slot, int $hours): string
    {
        return str_replace(
            ['{name}', '{slot}', '{hours}'],
            [$name, $slot, (string) $hours],
            $template
        );
    }

    /**
     * Setta la sessione dell'utente affinché la prossima risposta venga
     * gestita dal FlowRunner a partire dal nodo reminder (dove matcherà
     * il bottone cliccato e seguirà l'edge corrispondente).
     */
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

        // Solo se la sessione è "idle" (nessun flusso in corso)
        if ($session->current_node_id !== null || !empty($session->getData('__cursor'))) {
            return;
        }

        $session->update(['current_node_id' => $nodeId]);
        $session->mergeData(['selected_booking_id' => $bookingId]);
    }
}
