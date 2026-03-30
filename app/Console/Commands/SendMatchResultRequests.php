<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\BotSession;
use App\Models\MatchResult;
use App\Services\Bot\BotPersona;
use App\Services\Bot\BotState;
use App\Services\WhatsAppService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Invia richiesta di inserimento risultato a entrambi i giocatori
 * esattamente 1 ora dopo la fine della partita.
 *
 * Lanciabile manualmente:
 *   php artisan bot:send-result-requests
 *
 * Oppure schedulato (ogni 15 minuti in routes/console.php):
 *   Schedule::command('bot:send-result-requests')->everyFifteenMinutes();
 */
class SendMatchResultRequests extends Command
{
    protected $signature   = 'bot:send-result-requests
                              {--dry-run : Mostra le prenotazioni trovate senza inviare messaggi}';
    protected $description = 'Invia richiesta risultato ai giocatori 1h dopo la fine della partita';

    public function __construct(private readonly WhatsAppService $whatsApp)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        // Trova prenotazioni:
        // - status = confirmed
        // - con player2 (solo partite vere, non sparapalline)
        // - result_requested_at IS NULL (non ancora chiesto)
        // - end_time + 1h <= now() (la partita è finita da almeno 1 ora)
        $bookings = Booking::where('status', 'confirmed')
            ->whereNotNull('player2_id')
            ->whereNull('result_requested_at')
            ->whereRaw("ADDTIME(CONCAT(booking_date, ' ', end_time), '01:00:00') <= NOW()")
            ->with(['player1', 'player2'])
            ->get();

        if ($bookings->isEmpty()) {
            $this->info('Nessuna partita da processare.');
            return self::SUCCESS;
        }

        $this->info("Trovate {$bookings->count()} partita/e da processare.");

        foreach ($bookings as $booking) {
            $this->processBooking($booking, $dryRun);
        }

        return self::SUCCESS;
    }

    private function processBooking(Booking $booking, bool $dryRun): void
    {
        $player1 = $booking->player1;
        $player2 = $booking->player2;

        if (!$player1 || !$player2) {
            $this->warn("Booking #{$booking->id}: giocatori mancanti, skip.");
            return;
        }

        $dateStr  = Carbon::parse($booking->booking_date)->format('d/m');
        $timeStr  = mb_substr($booking->start_time, 0, 5);
        $slot     = "{$dateStr} alle {$timeStr}";

        $this->line("  → Booking #{$booking->id} — {$player1->name} vs {$player2->name} ({$slot})");

        if ($dryRun) {
            return;
        }

        try {
            // Crea MatchResult se non esiste ancora
            $matchResult = MatchResult::firstOrCreate(
                ['booking_id' => $booking->id],
                [
                    'winner_id'          => null,
                    'score'              => null,
                    'player1_confirmed'  => false,
                    'player2_confirmed'  => false,
                ],
            );

            // Manda messaggio a player1
            $this->notifyPlayer($player1->phone, 'player1', $booking->id, $slot);

            // Manda messaggio a player2
            $this->notifyPlayer($player2->phone, 'player2', $booking->id, $slot);

            // Segna come "richiesta inviata"
            $booking->update(['result_requested_at' => now()]);

            $this->info("    ✓ Messaggi inviati a {$player1->name} e {$player2->name}");

            Log::info('SendMatchResultRequests: richiesta inviata', [
                'booking_id' => $booking->id,
                'player1'    => $player1->phone,
                'player2'    => $player2->phone,
            ]);

        } catch (\Throwable $e) {
            $this->error("    ✗ Errore booking #{$booking->id}: {$e->getMessage()}");
            Log::error('SendMatchResultRequests: errore', [
                'booking_id' => $booking->id,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    private function notifyPlayer(string $phone, string $role, int $bookingId, string $slot): void
    {
        // Trova o crea sessione
        $session = BotSession::where('phone', $phone)->first();

        $resultData = [
            'result_booking_id' => $bookingId,
            'result_role'       => $role,
            'result_slot'       => $slot,
        ];

        if ($session) {
            $session->update(['state' => BotState::INSERISCI_RISULTATO->value]);
            $session->mergeData($resultData);
        } else {
            BotSession::create([
                'phone' => $phone,
                'state' => BotState::INSERISCI_RISULTATO->value,
                'data'  => array_merge([
                    'persona' => BotPersona::pickRandom(),
                    'history' => [],
                    'profile' => [],
                ], $resultData),
            ]);
        }

        $msg = "Com'è andata la partita di {$slot}? Inserisci il risultato! 🎾";
        $this->whatsApp->sendButtons($phone, $msg, ['Ho vinto', 'Ho perso', 'Non giocata']);
    }
}
