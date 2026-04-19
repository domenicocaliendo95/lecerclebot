<?php

namespace App\Services\Flow\Modules;

use App\Services\Flow\FlowContext;
use App\Services\Flow\Module;
use App\Services\Flow\ModuleMeta;
use App\Services\Flow\ModuleResult;

/**
 * Parsa un punteggio di tennis da testo libero e lo normalizza in formato ATP.
 *
 * Formati supportati:
 *   - Standard:    "6-3 6-4", "6-0, 6-1", "6/3 6/4"
 *   - Compresso:   "63 64", "60-26-76(6)"
 *   - Con tiebreak: "7-6(4)", "76(10)"
 *   - Naturale:    "ho vinto 6 a 3, 6 a 4", "sei zero il primo"
 *   - Risultato:   "ho vinto", "ho perso", "non giocata"
 *
 * Scrive in session.data:
 *   - match_result: 'won' | 'lost' | 'not_played'
 *   - match_score: stringa normalizzata ATP (es. "6-3 6-4")
 *   - match_sets: array di {player, opponent, tiebreak?}
 *
 * Porte: ok (parsato), non_giocata, non_capito
 */
class ParseRisultatoModule extends Module
{
    private const ITALIAN_NUMBERS = [
        'zero' => 0, 'uno' => 1, 'due' => 2, 'tre' => 3, 'quattro' => 4,
        'cinque' => 5, 'sei' => 6, 'sette' => 7, 'otto' => 8, 'nove' => 9,
        'dieci' => 10,
    ];

    public function meta(): ModuleMeta
    {
        return new ModuleMeta(
            key: 'parse_risultato',
            label: 'Interpreta risultato partita',
            category: 'dati',
            description: 'Parsa un punteggio di tennis da testo libero (es. "6-3 6-4", "ho vinto 6 a 3") e lo normalizza in formato ATP. Determina il vincitore.',
            configSchema: [
                'source' => [
                    'type'    => 'string',
                    'label'   => 'Sorgente',
                    'default' => 'last_input',
                    'help'    => 'Chiave in session.data. Default "last_input" (ultimo messaggio utente salvato dal runner).',
                ],
            ],
            icon: 'trophy',
        );
    }

    public function outputs(): array
    {
        return [
            'ok'          => 'Punteggio riconosciuto',
            'non_giocata' => 'Partita non giocata',
            'non_capito'  => 'Non capito',
        ];
    }

    public function execute(FlowContext $ctx): ModuleResult
    {
        $source = (string) $this->cfg('source', 'last_input');
        $raw    = trim($source === 'input' ? $ctx->input : (string) $ctx->get($source, ''));
        $lower  = mb_strtolower($raw);

        // ── Non giocata ──
        if ($this->matchesNotPlayed($lower)) {
            return ModuleResult::next('non_giocata')->withData([
                'match_result' => 'not_played',
                'match_score'  => null,
                'match_sets'   => [],
            ]);
        }

        // ── Determina se l'utente dice "ho vinto" / "ho perso" ──
        $declaredResult = $this->extractDeclaredResult($lower);

        // ── Prova a estrarre i set dal testo ──
        $sets = $this->parseSets($raw);

        if (empty($sets)) {
            // Se ha solo detto "ho vinto" / "ho perso" senza punteggio
            if ($declaredResult !== null) {
                return ModuleResult::next('ok')->withData([
                    'match_result' => $declaredResult,
                    'match_score'  => null,
                    'match_sets'   => [],
                ]);
            }
            return ModuleResult::next('non_capito');
        }

        // ── Calcola vincitore dai set ──
        $playerSetsWon = 0;
        $opponentSetsWon = 0;
        foreach ($sets as $set) {
            if ($set['player'] > $set['opponent']) {
                $playerSetsWon++;
            } else {
                $opponentSetsWon++;
            }
        }

        $result = $declaredResult
            ?? ($playerSetsWon > $opponentSetsWon ? 'won' : 'lost');

        // Normalizza in formato ATP
        $scoreStr = $this->toAtpFormat($sets, $result);

        return ModuleResult::next('ok')->withData([
            'match_result' => $result,
            'match_score'  => $scoreStr,
            'match_sets'   => $sets,
            'match_raw'    => $raw,
        ]);
    }

    private function matchesNotPlayed(string $lower): bool
    {
        $keywords = ['non giocata', 'non abbiamo giocato', 'annullata', 'saltata', 'cancellata', 'no show', 'non si è giocata'];
        foreach ($keywords as $kw) {
            if (str_contains($lower, $kw)) return true;
        }
        return false;
    }

    private function extractDeclaredResult(string $lower): ?string
    {
        $winWords = ['ho vinto', 'vittoria', 'abbiamo vinto', 'vinto io'];
        $loseWords = ['ho perso', 'sconfitta', 'perso io', 'abbiamo perso'];

        foreach ($winWords as $w) {
            if (str_contains($lower, $w)) return 'won';
        }
        foreach ($loseWords as $w) {
            if (str_contains($lower, $w)) return 'lost';
        }
        return null;
    }

    /**
     * Estrae i set dal testo. Supporta vari formati.
     *
     * @return array<int, array{player: int, opponent: int, tiebreak: ?int}>
     */
    private function parseSets(string $raw): array
    {
        // Converti numeri italiani in cifre
        $text = $this->italianToDigits(mb_strtolower($raw));

        // Prova formato compresso separato da dash: "60-26-76(6)"
        if (preg_match('/^(\d{2,3}(?:\(\d+\))?)([-\/](\d{2,3}(?:\(\d+\))?))+$/', preg_replace('/\s+/', '', $text))) {
            return $this->parseCompressed(preg_replace('/\s+/', '', $text), '-');
        }

        // Prova "X-Y" o "X/Y" con separatori
        $sets = [];
        if (preg_match_all('/(\d{1,2})\s*[-\/a]\s*(\d{1,2})(?:\s*\((\d{1,2})\))?/', $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $sets[] = [
                    'player'   => (int) $m[1],
                    'opponent' => (int) $m[2],
                    'tiebreak' => isset($m[3]) && $m[3] !== '' ? (int) $m[3] : null,
                ];
            }
        }

        if (!empty($sets)) return $sets;

        // Prova formato compresso: "63 64", "60 61"
        if (preg_match_all('/(\d)(\d)(?:\((\d{1,2})\))?/', $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $a = (int) $m[1];
                $b = (int) $m[2];
                if ($a <= 7 && $b <= 7 && ($a >= 4 || $b >= 4 || $a + $b >= 6)) {
                    $sets[] = [
                        'player'   => $a,
                        'opponent' => $b,
                        'tiebreak' => isset($m[3]) && $m[3] !== '' ? (int) $m[3] : null,
                    ];
                }
            }
        }

        return $sets;
    }

    private function parseCompressed(string $text, string $sep): array
    {
        $parts = explode($sep, $text);
        $sets = [];
        foreach ($parts as $part) {
            $tiebreak = null;
            if (preg_match('/^(\d)(\d)\((\d{1,2})\)$/', $part, $m)) {
                $sets[] = ['player' => (int) $m[1], 'opponent' => (int) $m[2], 'tiebreak' => (int) $m[3]];
            } elseif (preg_match('/^(\d)(\d)$/', $part, $m)) {
                $sets[] = ['player' => (int) $m[1], 'opponent' => (int) $m[2], 'tiebreak' => null];
            }
        }
        return $sets;
    }

    private function italianToDigits(string $text): string
    {
        foreach (self::ITALIAN_NUMBERS as $word => $digit) {
            $text = str_replace($word, (string) $digit, $text);
        }
        return $text;
    }

    /**
     * Normalizza in formato ATP: "6-3 2-6 7-6(6)"
     * Se il risultato è "lost", inverte i punteggi (mostra dal punto di vista
     * del vincitore, che è l'avversario).
     */
    private function toAtpFormat(array $sets, string $result): string
    {
        return implode(' ', array_map(function (array $set) use ($result) {
            $p = $set['player'];
            $o = $set['opponent'];
            // Mostra dal punto di vista di chi riporta
            $str = "{$p}-{$o}";
            if ($set['tiebreak'] !== null) {
                $str .= "({$set['tiebreak']})";
            }
            return $str;
        }, $sets));
    }
}
