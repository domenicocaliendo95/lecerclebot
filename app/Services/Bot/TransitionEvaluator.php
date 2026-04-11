<?php

namespace App\Services\Bot;

use App\Models\BotSession;

/**
 * Valuta `transitions` condizionali su una BotSession.
 *
 * Una transition object è formata da:
 *   { "if": { "field": "value" }, "then": "TARGET_STATE" }
 *
 * I `field` supportati sono:
 *   - profile.X        → legge da $session->data['profile'][X]
 *   - data.X           → legge da $session->data[X]
 *   - input            → l'input testuale grezzo (lowercase)
 *
 * Le condizioni multiple in `if` sono in AND.
 * Una transition senza `if` (o con `if` vuoto) è il default ("else").
 *
 * Restituisce il `then` della prima transition matchata, o null se nessuna.
 */
class TransitionEvaluator
{
    public function evaluate(array $transitions, BotSession $session, string $input = ''): ?string
    {
        foreach ($transitions as $t) {
            $if = $t['if'] ?? null;

            // Default transition (else)
            if ($if === null || (is_array($if) && empty($if))) {
                return $t['then'] ?? null;
            }

            if (!is_array($if)) {
                continue;
            }

            $allMatch = true;
            foreach ($if as $field => $expected) {
                $actual = $this->resolveField($field, $session, $input);
                if (!$this->compare($actual, $expected)) {
                    $allMatch = false;
                    break;
                }
            }

            if ($allMatch) {
                return $t['then'] ?? null;
            }
        }

        return null;
    }

    /**
     * Operatori di confronto disponibili (per UI).
     * Per ora supportiamo solo equals (semplice e leggibile).
     */
    public static function availableOperators(): array
    {
        return [
            'equals' => 'è uguale a',
            // Spazio per estensioni future: contains, gt, lt, exists, etc.
        ];
    }

    /**
     * Campi readable supportati. Per il dropdown del frontend.
     */
    public static function availableFields(): array
    {
        return [
            'profile.is_fit'         => 'Profilo: tesserato FIT',
            'profile.self_level'     => 'Profilo: livello autodichiarato',
            'profile.fit_rating'     => 'Profilo: classifica FIT',
            'profile.preferred_slots'=> 'Profilo: fascia oraria preferita',
            'data.booking_type'      => 'Sessione: tipo prenotazione',
            'data.payment_method'    => 'Sessione: metodo pagamento',
            'data.update_field'      => 'Sessione: campo in modifica',
            'input'                  => 'Ultimo input dell\'utente',
        ];
    }

    /* ───────── Internals ───────── */

    private function resolveField(string $field, BotSession $session, string $input): mixed
    {
        if ($field === 'input') {
            return mb_strtolower(trim($input));
        }

        return $session->getData($field);
    }

    private function compare(mixed $actual, mixed $expected): bool
    {
        // Booleans tollerati: "yes"/"no"/"true"/"false"
        if (is_bool($actual)) {
            $expectedNorm = is_string($expected) ? strtolower($expected) : $expected;
            return $actual === ($expectedNorm === true || $expectedNorm === 'true' || $expectedNorm === '1' || $expectedNorm === 'yes');
        }
        if (is_bool($expected)) {
            $actualNorm = is_string($actual) ? strtolower($actual) : $actual;
            return $expected === ($actualNorm === true || $actualNorm === 'true' || $actualNorm === '1' || $actualNorm === 'yes');
        }

        // Stringa: confronto case-insensitive
        if (is_string($actual) && is_string($expected)) {
            return mb_strtolower(trim($actual)) === mb_strtolower(trim($expected));
        }

        return $actual == $expected;
    }
}
