<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\MatchResult;
use App\Services\WhatsAppService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Invia richiesta di inserimento risultato a entrambi i giocatori
 * esattamente 1 ora dopo la fine della partita.
 *
 * Durante la migrazione al nuovo FlowRunner, questo comando si limita a
 * spedire il messaggio di richiesta e a creare il MatchResult. L'interazione
 * successiva (parsing della risposta, aggiornamento ELO) verrà gestita come
 * grafo nel nuovo editor quando il flusso "inserisci risultato" sarà seedato.
 *
 * Lanciabile manualmente:
 *   php artisan bot:send-result-requests
 *   php artisan bot:send-result-requests --dry-run
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
        $player2 = $booking->player2;
        $player2Name = $player2?->name ?? $booking->player2_name_text;

        if (!$player1) {
            $this->warn("Booking #{$booking->id}: player1 mancante, skip.");
            return;
        }

        $dateStr = Carbon::parse($booking->booking_date)->format('d/m');
        $timeStr = mb_substr($booking->start_time, 0, 5);
        $slot    = "{$dateStr} alle {$timeStr}";

        $isTracked = $player2 !== null && $booking->player2_confirmed_at !== null;

        $vsLabel = $player2Name ? "{$player1->name} vs {$player2Name}" : "{$player1->name} (avv. esterno)";
        $this->line("  → Booking #{$booking->id} — {$vsLabel} ({$slot})" . ($isTracked ? '' : ' [no ELO]'));

        if ($dryRun) {
            return;
        }

        try {
            if ($isTracked) {
                MatchResult::firstOrCreate(
                    ['booking_id' => $booking->id],
                    [
                        'winner_id'         => null,
                        'score'             => null,
                        'player1_confirmed' => false,
                        'player2_confirmed' => false,
                    ],
                );
            }

            $msg = "Com'è andata la partita di {$slot}? Scrivimi 'risultato' per inserirla 🎾";

            $this->whatsApp->sendText($player1->phone, $msg);

            if ($player2 && $player2->phone) {
                $this->whatsApp->sendText($player2->phone, $msg);
            }

            $booking->update(['result_requested_at' => now()]);

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
}
