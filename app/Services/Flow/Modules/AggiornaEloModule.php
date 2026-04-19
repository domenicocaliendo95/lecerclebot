<?php

namespace App\Services\Flow\Modules;

use App\Models\Booking;
use App\Models\MatchResult;
use App\Services\EloService;
use App\Services\Flow\FlowContext;
use App\Services\Flow\Module;
use App\Services\Flow\ModuleMeta;
use App\Services\Flow\ModuleResult;
use Illuminate\Support\Facades\Log;

/**
 * Aggiorna l'ELO di entrambi i giocatori dopo un risultato confermato.
 *
 * Legge da session.data:
 *   - match_result: 'won' | 'lost' | 'not_played'
 *   - match_score: stringa ATP (opzionale)
 *   - result_booking_id: int
 *
 * Crea/aggiorna il MatchResult, determina il vincitore, e chiama
 * EloService::processResult() se entrambi i giocatori sono tracciati
 * (player2_confirmed_at != null).
 *
 * Porte: aggiornato (ELO cambiato), skip (no ELO — avversario non tracciato
 * o partita non giocata), errore.
 */
class AggiornaEloModule extends Module
{
    public function meta(): ModuleMeta
    {
        return new ModuleMeta(
            key: 'aggiorna_elo',
            label: 'Aggiorna classifica ELO',
            category: 'azione',
            description: 'Aggiorna il punteggio ELO di entrambi i giocatori dopo un risultato confermato. Crea il MatchResult e chiama EloService.',
            icon: 'trophy',
        );
    }

    public function outputs(): array
    {
        return [
            'aggiornato' => 'ELO aggiornato',
            'skip'       => 'Nessun aggiornamento ELO',
            'errore'     => 'Errore',
        ];
    }

    public function execute(FlowContext $ctx): ModuleResult
    {
        $result    = (string) ($ctx->get('match_result') ?? '');
        $score     = (string) ($ctx->get('match_score') ?? '');
        $bookingId = $ctx->get('result_booking_id') ?? $ctx->get('selected_booking_id');

        if ($result === 'not_played' || $result === '') {
            return ModuleResult::next('skip');
        }

        if (!$bookingId) {
            Log::warning('aggiorna_elo: no booking_id in session');
            return ModuleResult::next('skip');
        }

        try {
            $booking = Booking::with(['player1', 'player2'])->find($bookingId);
            if (!$booking || !$booking->player1) {
                return ModuleResult::next('skip');
            }

            // Determina chi è il player che sta rispondendo
            $respondent = $ctx->user;
            if (!$respondent) {
                return ModuleResult::next('skip');
            }

            $isPlayer1 = $respondent->id === $booking->player1_id;
            $winnerId  = null;

            if ($result === 'won') {
                $winnerId = $respondent->id;
            } elseif ($result === 'lost') {
                $winnerId = $isPlayer1 ? $booking->player2_id : $booking->player1_id;
            }

            // Crea o aggiorna MatchResult
            $matchResult = MatchResult::updateOrCreate(
                ['booking_id' => $bookingId],
                [
                    'winner_id' => $winnerId,
                    'score'     => $score ?: null,
                    $isPlayer1 ? 'player1_confirmed' : 'player2_confirmed' => true,
                ],
            );

            // ELO solo se entrambi tracciati (player2_confirmed_at set)
            $isTracked = $booking->player2_id !== null
                && $booking->player2_confirmed_at !== null;

            if (!$isTracked || !$winnerId) {
                return ModuleResult::next('skip');
            }

            // Se un solo giocatore ha confermato, aspettiamo l'altro
            if (!$matchResult->player1_confirmed || !$matchResult->player2_confirmed) {
                return ModuleResult::next('skip')->withData([
                    'elo_note' => 'In attesa della conferma dell\'altro giocatore',
                ]);
            }

            // Entrambi hanno confermato → calcola ELO
            app(EloService::class)->processResult($matchResult);

            $booking->refresh();
            $respondent->refresh();

            return ModuleResult::next('aggiornato')->withData([
                'elo_new'   => $respondent->elo_rating,
                'elo_delta' => $matchResult->{'player' . ($isPlayer1 ? '1' : '2') . '_elo_after'}
                    - $matchResult->{'player' . ($isPlayer1 ? '1' : '2') . '_elo_before'},
            ]);
        } catch (\Throwable $e) {
            Log::error('aggiorna_elo failed', ['error' => $e->getMessage()]);
            return ModuleResult::next('errore');
        }
    }
}
