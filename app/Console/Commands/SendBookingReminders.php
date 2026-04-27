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

class SendBookingReminders extends Command
{
    protected $signature = 'bot:send-reminders {--dry-run}';
    protected $description = 'Invia promemoria prenotazioni (dedup su DB, gira ogni 5 min)';

    public function handle(ChannelRegistry $channels): int
    {
        $now   = Carbon::now('Europe/Rome');
        $isDry = $this->option('dry-run');

        Log::info('🔔 bot:send-reminders START', ['now' => $now->toIso8601String(), 'dry' => $isDry]);

        $settings = BotSetting::get('reminders', ['enabled' => true, 'slots' => []]);

        if (!($settings['enabled'] ?? true)) {
            Log::info('🔔 Reminders DISABILITATI');
            $this->info('Reminders disabilitati.');
            return self::SUCCESS;
        }

        $slots = collect($settings['slots'] ?? [])
            ->filter(fn($s) => ($s['enabled'] ?? false) && !empty($s['flow_node_id']))
            ->values();

        if ($slots->isEmpty()) {
            Log::info('🔔 Nessuno slot attivo');
            $this->info('Nessuno slot reminder attivo.');
            return self::SUCCESS;
        }

        Log::info('🔔 Slot attivi: ' . $slots->count(), [
            'slots' => $slots->map(fn($s) => $s['hours_before'] . 'h')->all(),
        ]);

        $adapter = $channels->get('whatsapp');
        if (!$adapter) {
            Log::error('🔔 WhatsApp adapter NON TROVATO!');
            $this->error('WhatsApp adapter non trovato.');
            return self::FAILURE;
        }

        $sent = 0;

        foreach ($slots as $slot) {
            $hoursBefore = (int) ($slot['hours_before'] ?? 0);
            $slotKey     = (string) $hoursBefore;
            $nodeId      = (int) $slot['flow_node_id'];

            $flowNode = FlowNode::find($nodeId);
            if (!$flowNode) {
                Log::warning("🔔 Slot {$hoursBefore}h: nodo {$nodeId} non trovato");
                continue;
            }

            $config  = $flowNode->config ?? [];
            $text    = (string) ($config['text'] ?? 'Promemoria prenotazione');
            $buttons = collect($config['buttons'] ?? [])
                ->map(fn($b) => is_array($b) ? (string) ($b['label'] ?? '') : (string) $b)
                ->filter(fn($l) => $l !== '')
                ->values()->all();

            $deadlineMax = $now->copy()->addHours($hoursBefore)->addMinutes(10);
            $minHours = $hoursBefore >= 6 ? (int) ($hoursBefore / 2) : 0;
            $deadlineMin = $now->copy()->addHours($minHours);

            Log::info("🔔 Slot {$hoursBefore}h: cerco prenotazioni tra {$deadlineMin->format('d/m H:i')} e {$deadlineMax->format('d/m H:i')}");

            $bookings = Booking::whereIn('status', ['confirmed', 'pending_match'])
                ->whereNotNull('player1_id')
                ->whereRaw(
                    "CONCAT(booking_date, ' ', start_time) BETWEEN ? AND ?",
                    [$deadlineMin->format('Y-m-d H:i:s'), $deadlineMax->format('Y-m-d H:i:s')]
                )
                ->with(['player1', 'player2'])
                ->get();

            Log::info("🔔 Slot {$hoursBefore}h: trovate {$bookings->count()} prenotazioni");

            foreach ($bookings as $booking) {
                $alreadySent = $booking->reminders_sent[$slotKey] ?? false;
                if ($alreadySent) {
                    Log::info("🔔 Booking #{$booking->id}: già inviato per slot {$slotKey}h, skip");
                    continue;
                }

                $slotLabel = Carbon::parse($booking->booking_date)
                    ->locale('it')->isoFormat('dddd D MMMM')
                    . ' alle ' . substr($booking->start_time, 0, 5);

                $players = array_filter([
                    $booking->player1 && $booking->player1->phone ? $booking->player1 : null,
                    $booking->player2 && $booking->player2->phone ? $booking->player2 : null,
                ]);

                if (empty($players)) {
                    Log::info("🔔 Booking #{$booking->id}: nessun giocatore con telefono, skip");
                    continue;
                }

                if ($isDry) {
                    foreach ($players as $player) {
                        $msg = str_replace(['{name}', '{slot}', '{hours}'], [$player->name, $slotLabel, (string) $hoursBefore], $text);
                        $this->line("  [DRY] {$slotKey}h · {$player->phone}: {$msg}");
                    }
                    $sent++;
                    continue;
                }

                foreach ($players as $player) {
                    $msg = str_replace(
                        ['{name}', '{slot}', '{hours}'],
                        [$player->name, $slotLabel, (string) $hoursBefore],
                        $text
                    );

                    try {
                        if (!empty($buttons)) {
                            $adapter->sendButtons($player->phone, $msg, $buttons);
                        } else {
                            $adapter->sendText($player->phone, $msg);
                        }

                        if (!empty($buttons)) {
                            $this->setCursorForResponse($player->phone, $booking->id, $nodeId);
                        }

                        $this->logToHistory($player->phone, $msg);

                        Log::info("🔔 ✅ Reminder INVIATO", [
                            'booking' => $booking->id,
                            'phone'   => $player->phone,
                            'slot'    => $slotKey . 'h',
                        ]);
                    } catch (\Throwable $e) {
                        Log::error("🔔 ❌ Reminder FALLITO", [
                            'booking' => $booking->id,
                            'phone'   => $player->phone,
                            'error'   => $e->getMessage(),
                        ]);
                    }
                }

                $existing = $booking->reminders_sent ?? [];
                $existing[$slotKey] = now()->toIso8601String();
                $booking->update(['reminders_sent' => $existing]);
                $sent++;
            }
        }

        Log::info("🔔 bot:send-reminders END — notificate: {$sent}");
        $this->info(($isDry ? '[DRY] ' : '') . "Prenotazioni notificate: {$sent}");
        return self::SUCCESS;
    }

    private function logToHistory(string $phone, string $message): void
    {
        $session = BotSession::where('channel', 'whatsapp')
            ->where('external_id', $phone)->first();
        $session?->appendHistory('bot', $message);
    }

    private function setCursorForResponse(string $phone, int $bookingId, int $nodeId): void
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
        $session->mergeData(['selected_booking_id' => $bookingId]);
    }
}
