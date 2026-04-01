<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\BotSetting;
use App\Services\WhatsAppService;
use App\Services\Bot\TextGenerator;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendBookingReminders extends Command
{
    protected $signature = 'bot:send-reminders
                           {--dry-run : Mostra le prenotazioni trovate senza inviare messaggi}';

    protected $description = 'Invia promemoria WhatsApp per le prenotazioni imminenti';

    public function handle(WhatsAppService $whatsApp, TextGenerator $textGenerator): int
    {
        $settings = BotSetting::get('reminders', [
            'enabled' => true,
            'slots'   => [
                ['hours_before' => 24, 'enabled' => true],
                ['hours_before' => 2,  'enabled' => true],
            ],
        ]);

        if (!($settings['enabled'] ?? true)) {
            $this->info('Reminders disabilitati nelle impostazioni.');
            return self::SUCCESS;
        }

        $slots = collect($settings['slots'] ?? [])
            ->filter(fn($s) => $s['enabled'] ?? false)
            ->sortByDesc('hours_before')
            ->values();

        if ($slots->isEmpty()) {
            $this->info('Nessuno slot reminder attivo.');
            return self::SUCCESS;
        }

        $now   = Carbon::now('Europe/Rome');
        $sent  = 0;
        $isDry = $this->option('dry-run');

        foreach ($slots as $slot) {
            $hoursBefore = (int) $slot['hours_before'];
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
                $slotLabel = Carbon::parse($booking->booking_date)->locale('it')->isoFormat('dddd D MMMM')
                    . ' alle ' . substr($booking->start_time, 0, 5);

                $reminderKey = "reminder_{$hoursBefore}h_sent";

                // Skip se già inviato (usa campo metadata nella tabella — controlliamo via cache semplice)
                $cacheKey = "reminder:{$booking->id}:{$hoursBefore}h";
                if (cache()->has($cacheKey)) {
                    continue;
                }

                if ($isDry) {
                    $this->line("  [DRY] Booking #{$booking->id} — {$slotLabel} — reminder {$hoursBefore}h");
                    $sent++;
                    continue;
                }

                // Invia a player 1
                $this->sendReminder($whatsApp, $textGenerator, $booking->player1, $slotLabel, $hoursBefore);

                // Invia a player 2 se presente
                if ($booking->player2) {
                    $this->sendReminder($whatsApp, $textGenerator, $booking->player2, $slotLabel, $hoursBefore);
                }

                // Segna come inviato (cache 48h per evitare duplicati)
                cache()->put($cacheKey, true, now()->addHours(48));
                $sent++;
            }
        }

        $this->info(($isDry ? '[DRY RUN] ' : '') . "Reminders inviati: {$sent}");

        return self::SUCCESS;
    }

    private function sendReminder(
        WhatsAppService $whatsApp,
        TextGenerator $textGenerator,
        ?\App\Models\User $player,
        string $slotLabel,
        int $hoursBefore,
    ): void {
        if (!$player || !$player->phone) {
            return;
        }

        try {
            $templateId = $hoursBefore >= 12 ? 'reminder_giorno_prima' : 'reminder_ore_prima';
            $msg = $textGenerator->rephrase($templateId, 'Bot', [
                'slot'  => $slotLabel,
                'hours' => $hoursBefore,
            ]);

            $whatsApp->sendText($player->phone, $msg);

            Log::info('Reminder inviato', [
                'booking'      => $player->phone,
                'hours_before' => $hoursBefore,
                'slot'         => $slotLabel,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Reminder send failed', [
                'phone' => $player->phone,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
