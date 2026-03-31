<?php

namespace App\Console\Commands;

use App\Models\BotSession;
use App\Models\User;
use App\Services\Bot\BotOrchestrator;
use App\Services\Bot\BotState;
use App\Services\Bot\TextGenerator;
use App\Services\WhatsAppService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Riprova la ricerca matchmaking per i challenger in ATTESA_MATCH
 * che non hanno ancora un avversario assegnato (matchmaking_pending_search = true).
 *
 * Strategia:
 *  - Ogni 5 minuti: riprova la ricerca a 3 livelli ELO
 *  - Dopo 30 minuti senza trovare nessuno: avvisa il challenger e torna al MENU
 *
 * Uso manuale:
 *   php artisan bot:retry-matchmaking
 *   php artisan bot:retry-matchmaking --dry-run
 */
class RetryPendingMatchmaking extends Command
{
    protected $signature   = 'bot:retry-matchmaking {--dry-run : Mostra i risultati senza inviare messaggi}';
    protected $description = 'Riprova matchmaking per i challenger in attesa (timeout 30 min)';

    private const TIMEOUT_MINUTES = 30;

    public function __construct(
        private readonly BotOrchestrator $orchestrator,
        private readonly TextGenerator   $textGenerator,
        private readonly WhatsAppService $whatsApp,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        // Sessioni in ATTESA_MATCH con ricerca pendente
        $sessions = BotSession::where('state', BotState::ATTESA_MATCH->value)
            ->get()
            ->filter(fn(BotSession $s) => $s->getData('matchmaking_pending_search') === true);

        if ($sessions->isEmpty()) {
            $this->info('Nessuna ricerca matchmaking pendente.');
            return self::SUCCESS;
        }

        $this->info("Sessioni da verificare: {$sessions->count()}");

        foreach ($sessions as $session) {
            $this->processSession($session, $dryRun);
        }

        return self::SUCCESS;
    }

    private function processSession(BotSession $session, bool $dryRun): void
    {
        $phone     = $session->phone;
        $startedAt = $session->getData('matchmaking_started_at');

        if (!$startedAt) {
            Log::warning('RetryMatchmaking: matchmaking_started_at mancante', ['phone' => $phone]);
            return;
        }

        $elapsed = Carbon::parse($startedAt)->diffInMinutes(now());

        $challenger = User::where('phone', $phone)->first();
        if (!$challenger) {
            Log::warning('RetryMatchmaking: challenger not found', ['phone' => $phone]);
            return;
        }

        $challengerElo = $challenger->elo_rating ?? 1200;

        // Timeout scaduto → avvisa e torna al MENU
        if ($elapsed >= self::TIMEOUT_MINUTES) {
            $this->line("  [{$phone}] Timeout ({$elapsed} min) — nessun avversario trovato.");

            if (!$dryRun) {
                $this->whatsApp->sendButtons(
                    $phone,
                    $this->textGenerator->rephrase('nessun_avversario', $session->persona()),
                    ['Cambia orario', 'Menu'],
                );
                $session->mergeData([
                    'matchmaking_pending_search' => false,
                    'matchmaking_started_at'     => null,
                ]);
                $session->update(['state' => BotState::MENU->value]);
            }

            Log::info('RetryMatchmaking: timeout, notified challenger', [
                'phone'   => $phone,
                'elapsed' => $elapsed,
            ]);
            return;
        }

        // Timeout non scaduto → riprova la ricerca a 3 livelli
        [$opponent, $eloGap] = $this->orchestrator->findOpponentTiered($challenger->id, $challengerElo);

        if (!$opponent) {
            $this->line("  [{$phone}] Nessun avversario ancora (elapsed: {$elapsed} min).");
            return;
        }

        $this->line("  [{$phone}] Avversario trovato: {$opponent->name} (ELO gap: {$eloGap}).");

        if (!$dryRun) {
            $date     = $session->getData('requested_date');
            $time     = $session->getData('requested_time');
            $friendly = $session->getData('requested_friendly') ?? "{$date} {$time}";

            $this->orchestrator->sendMatchInvite(
                session:          $session,
                challengerPhone:  $phone,
                challenger:       $challenger,
                opponent:         $opponent,
                eloGap:           $eloGap,
                date:             $date,
                time:             $time,
                friendly:         $friendly,
            );
        }

        Log::info('RetryMatchmaking: invite sent', [
            'phone'      => $phone,
            'opponent'   => $opponent->name,
            'elo_gap'    => $eloGap,
            'elapsed'    => $elapsed,
        ]);
    }
}
