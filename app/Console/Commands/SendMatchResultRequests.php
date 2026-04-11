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
        // - partita reale (non sparapalline): player2_id O player2_name_text settati
        // - result_requested_at IS NULL (non ancora chiesto)
        // - end_time + 1h <= now() (la partita è finita da almeno 1 ora)
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

        $this->info("Trovate {$bookings->count()} partita/e da processare.");

        foreach ($bookings as $booking) {
            $this->processBooking($booking, $dryRun);
        }

        return self::SUCCESS;
    }

    private function processBooking(Booking $booking, bool $dryRun): void
    {
        $player1 = $booking->player1;
        $player2 = $booking->player2;                  // potrebbe essere null
        $player2Name = $player2?->name ?? $booking->player2_name_text;

        if (!$player1) {
            $this->warn("Booking #{$booking->id}: player1 mancante, skip.");
            return;
        }

        $dateStr = Carbon::parse($booking->booking_date)->format('d/m');
        $timeStr = mb_substr($booking->start_time, 0, 5);
        $slot    = "{$dateStr} alle {$timeStr}";

        // Tracciabilità ELO solo se BOTH player presenti E player2 ha confermato il link
        $isTracked = $player2 !== null && $booking->player2_confirmed_at !== null;

        $vsLabel = $player2Name ? "{$player1->name} vs {$player2Name}" : "{$player1->name} (avv. esterno)";
        $this->line("  → Booking #{$booking->id} — {$vsLabel} ({$slot})" . ($isTracked ? '' : ' [no ELO]'));

        if ($dryRun) {
            return;
        }

        try {
            // Crea MatchResult solo se la partita è tracciabile per ELO
            if ($isTracked) {
                MatchResult::firstOrCreate(
                    ['booking_id' => $booking->id],
                    [
                        'winner_id'          => null,
                        'score'              => null,
                        'player1_confirmed'  => false,
                        'player2_confirmed'  => false,
                    ],
                );
            }

            // Manda messaggio a player1 (sempre)
            $this->notifyPlayer($player1->phone, 'player1', $booking->id, $slot);

            // Manda messaggio a player2 SOLO se è un utente del circolo con phone
            if ($player2 && $player2->phone) {
                $this->notifyPlayer($player2->phone, 'player2', $booking->id, $slot);
            }

            $booking->update(['result_requested_at' => now()]);

            $msg = $player2 && $player2->phone
                ? "    ✓ Messaggi inviati a {$player1->name} e {$player2->name}"
                : "    ✓ Messaggio inviato a {$player1->name} (avversario non tracciato)";
            $this->info($msg);

            Log::info('SendMatchResultRequests: richiesta inviata', [
                'booking_id' => $booking->id,
                'player1'    => $player1->phone,
                'player2'    => $player2?->phone,
                'tracked'    => $isTracked,
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
