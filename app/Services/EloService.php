<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\EloHistory;
use App\Models\MatchResult;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Gestisce il calcolo e l'aggiornamento dell'ELO.
 *
 * Formula standard:
 *   E  = 1 / (1 + 10^((opponent_elo - player_elo) / 400))
 *   E' = elo + K * (actual - expected)
 *
 * K-factor:
 *   32 → giocatore con < 20 partite (periodo di calibrazione)
 *   16 → giocatore stabilizzato (>= 20 partite)
 */
class EloService
{
    private const K_INITIAL      = 32;
    private const K_ESTABLISHED  = 16;
    private const ESTABLISHED_AT = 20; // partite per stabilizzarsi

    /**
     * Calcola il punteggio atteso (probabilità di vittoria).
     */
    public function expectedScore(int $playerElo, int $opponentElo): float
    {
        return 1 / (1 + 10 ** (($opponentElo - $playerElo) / 400));
    }

    /**
     * Calcola il nuovo ELO dopo una partita.
     *
     * @param bool $isEstablished  true se il giocatore ha già >= ESTABLISHED_AT partite
     */
    public function newElo(int $elo, float $expected, bool $won, bool $isEstablished): int
    {
        $k      = $isEstablished ? self::K_ESTABLISHED : self::K_INITIAL;
        $actual = $won ? 1.0 : 0.0;

        return (int) round($elo + $k * ($actual - $expected));
    }

    /**
     * Processa il risultato di una partita:
     * - Calcola i nuovi ELO per entrambi i giocatori
     * - Aggiorna MatchResult con ELO before/after
     * - Scrive EloHistory per entrambi
     * - Aggiorna User (elo_rating, matches_played, matches_won, is_elo_established)
     *
     * Da chiamare SOLO quando entrambi i giocatori hanno confermato e concordano.
     */
    public function processResult(MatchResult $matchResult): void
    {
        DB::transaction(function () use ($matchResult) {
            $booking = $matchResult->booking()->with(['player1', 'player2'])->first();

            if (!$booking?->player1 || !$booking?->player2) {
                Log::error('EloService: booking senza giocatori', [
                    'booking_id'      => $matchResult->booking_id,
                    'match_result_id' => $matchResult->id,
                ]);
                return;
            }

            $player1   = $booking->player1;
            $player2   = $booking->player2;
            $winnerId  = $matchResult->winner_id;

            if (!$winnerId) {
                Log::warning('EloService: nessun vincitore dichiarato', [
                    'match_result_id' => $matchResult->id,
                ]);
                return;
            }

            $p1Won = ($winnerId === $player1->id);

            // ELO prima
            $elo1Before = $player1->elo_rating;
            $elo2Before = $player2->elo_rating;

            // Punteggi attesi
            $expected1 = $this->expectedScore($elo1Before, $elo2Before);
            $expected2 = $this->expectedScore($elo2Before, $elo1Before);

            // Nuovi ELO
            $elo1After = $this->newElo($elo1Before, $expected1, $p1Won,  $player1->is_elo_established);
            $elo2After = $this->newElo($elo2Before, $expected2, !$p1Won, $player2->is_elo_established);

            // Aggiorna MatchResult
            $matchResult->update([
                'player1_elo_before' => $elo1Before,
                'player1_elo_after'  => $elo1After,
                'player2_elo_before' => $elo2Before,
                'player2_elo_after'  => $elo2After,
                'confirmed_at'       => now(),
            ]);

            // Aggiorna giocatori e scrivi history
            $this->updatePlayer($player1, $elo1Before, $elo1After, $p1Won, $matchResult);
            $this->updatePlayer($player2, $elo2Before, $elo2After, !$p1Won, $matchResult);

            // Aggiorna booking a 'completed'
            $booking->update(['status' => 'completed']);

            Log::info('EloService: ELO aggiornato', [
                'match_result_id' => $matchResult->id,
                'player1'         => "{$player1->name} {$elo1Before}→{$elo1After}",
                'player2'         => "{$player2->name} {$elo2Before}→{$elo2After}",
                'winner'          => $winnerId,
            ]);
        });
    }

    /**
     * Aggiorna il singolo utente e scrive il record EloHistory.
     */
    private function updatePlayer(
        User        $player,
        int         $eloBefore,
        int         $eloAfter,
        bool        $won,
        MatchResult $matchResult,
    ): void {
        $newMatchesPlayed = $player->matches_played + 1;
        $newMatchesWon    = $player->matches_won + ($won ? 1 : 0);
        $isEstablished    = $newMatchesPlayed >= self::ESTABLISHED_AT;

        $player->update([
            'elo_rating'        => $eloAfter,
            'matches_played'    => $newMatchesPlayed,
            'matches_won'       => $newMatchesWon,
            'is_elo_established'=> $isEstablished,
        ]);

        EloHistory::create([
            'user_id'         => $player->id,
            'match_result_id' => $matchResult->id,
            'elo_before'      => $eloBefore,
            'elo_after'       => $eloAfter,
            'delta'           => $eloAfter - $eloBefore,
            'reason'          => 'match',
        ]);
    }
}
