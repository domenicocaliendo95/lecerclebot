<?php

namespace App\Services\Bot;

/**
 * Valuta le `input_rules` di uno stato e restituisce il primo match.
 *
 * Le rules vengono pensate per essere editabili dal pannello senza scrivere
 * né regex né codice (le opzioni esposte dal frontend sono "tipi friendly").
 *
 * Tipi supportati:
 *  - name           : nome di persona (lettere/spazi/apostrofi, 2-60 char) → Title Case
 *  - integer_range  : numero intero compreso tra min e max
 *  - mapping        : selezione con sinonimi (es. "neofita: principiante, inizio")
 *  - regex          : (avanzato) pattern PCRE con eventuale gruppo da catturare
 *  - free_text      : qualunque testo non vuoto
 *
 * Una rule che matcha può:
 *  - trasformare il valore (`title_case`, `lowercase`, `uppercase`, `int`)
 *  - salvare il valore in `profile.X` o `data.X` della sessione
 *  - indicare il prossimo stato (`next_state`)
 *  - applicare un side_effect (whitelist standard)
 */
class RuleEvaluator
{
    /**
     * Valuta le rules contro l'input. Restituisce il match o null.
     *
     * @return array{rule: array, value: mixed}|null
     */
    public function evaluate(array $rules, string $input): ?array
    {
        $clean = trim($input);
        if ($clean === '') {
            return null;
        }

        foreach ($rules as $rule) {
            $value = $this->matchRule($rule, $clean);
            if ($value !== null) {
                return [
                    'rule'  => $rule,
                    'value' => $this->applyTransform($value, $rule['transform'] ?? null),
                ];
            }
        }

        return null;
    }

    /**
     * Tipi friendly disponibili. Esposti al frontend per popolare il picker.
     *
     * @return array<string, array{label: string, description: string, fields: array}>
     */
    public static function availableRuleTypes(): array
    {
        return [
            'name' => [
                'label'       => 'Nome di persona',
                'description' => 'Solo lettere, spazi e apostrofi (2-60 caratteri). Capitalizza automaticamente.',
                'fields'      => [],
            ],
            'integer_range' => [
                'label'       => 'Numero intero',
                'description' => 'Estrae il primo numero intero dall\'input. Opzionalmente entro un range.',
                'fields'      => [
                    ['key' => 'min', 'label' => 'Minimo (opzionale)', 'type' => 'number'],
                    ['key' => 'max', 'label' => 'Massimo (opzionale)', 'type' => 'number'],
                ],
            ],
            'mapping' => [
                'label'       => 'Una di queste opzioni',
                'description' => 'L\'utente può scrivere una qualsiasi delle parole chiave che riconducono a un valore.',
                'fields'      => [
                    [
                        'key'         => 'options',
                        'label'       => 'Opzioni e sinonimi',
                        'type'        => 'mapping_table',
                        'placeholder' => 'es. mattina: mattino, presto',
                    ],
                ],
            ],
            'regex' => [
                'label'       => 'Espressione regolare (avanzato)',
                'description' => 'Per casi che non rientrano negli altri tipi. Sintassi PCRE.',
                'fields'      => [
                    ['key' => 'pattern',       'label' => 'Pattern',                          'type' => 'text'],
                    ['key' => 'capture_group', 'label' => 'Gruppo da catturare (es. 1)',       'type' => 'number'],
                ],
            ],
            'free_text' => [
                'label'       => 'Testo libero',
                'description' => 'Accetta qualsiasi input non vuoto. Utile per commenti e descrizioni.',
                'fields'      => [],
            ],
        ];
    }

    /**
     * Trasformazioni applicabili al valore matchato.
     */
    public static function availableTransforms(): array
    {
        return [
            'none'       => 'Nessuna',
            'title_case' => 'Mario Rossi (Title Case)',
            'lowercase'  => 'minuscolo',
            'uppercase'  => 'MAIUSCOLO',
            'int'        => 'Numero intero',
        ];
    }

    /* ───────── Internals ───────── */

    private function matchRule(array $rule, string $input): mixed
    {
        $type = $rule['type'] ?? null;
        return match ($type) {
            'name'          => $this->matchName($input),
            'integer_range' => $this->matchIntegerRange($input, $rule),
            'mapping'       => $this->matchMapping($input, $rule),
            'regex'         => $this->matchRegex($input, $rule),
            'free_text'     => $input,
            default         => null,
        };
    }

    /* ── Tipo: name ───────────────────────────────────────────── */

    private function matchName(string $input): ?string
    {
        // Lettere unicode, spazi, apostrofi. 2-60 char.
        if (preg_match("/^[\p{L}\s']{2,60}$/u", $input)) {
            return $input;
        }
        return null;
    }

    /* ── Tipo: integer_range ───────────────────────────────────── */

    private function matchIntegerRange(string $input, array $rule): ?int
    {
        if (!preg_match('/(\d+)/', $input, $m)) {
            return null;
        }

        $value = (int) $m[1];
        $min   = $rule['min'] ?? null;
        $max   = $rule['max'] ?? null;

        if ($min !== null && $value < (int) $min) {
            return null;
        }
        if ($max !== null && $value > (int) $max) {
            return null;
        }

        return $value;
    }

    /* ── Tipo: mapping ─────────────────────────────────────────── */

    /**
     * `options` può essere:
     *  - array associativo:  ['neofita' => ['principiante','inizio'], 'avanzato' => ['esperto']]
     *  - array di righe testuali: ['neofita: principiante, inizio', 'avanzato: esperto']
     */
    private function matchMapping(string $input, array $rule): ?string
    {
        $clean   = mb_strtolower($input);
        $options = $rule['options'] ?? [];

        // Normalizza in formato canonico [valore => [sinonimi...]]
        $normalized = [];
        if (is_array($options)) {
            foreach ($options as $key => $val) {
                if (is_int($key) && is_string($val)) {
                    // Riga testuale "valore: sin1, sin2"
                    if (str_contains($val, ':')) {
                        [$canonical, $synonyms] = array_map('trim', explode(':', $val, 2));
                        $synList = array_filter(array_map('trim', explode(',', $synonyms)));
                        $normalized[mb_strtolower($canonical)] = array_merge([mb_strtolower($canonical)], array_map('mb_strtolower', $synList));
                    }
                } elseif (is_string($key)) {
                    $synList = is_array($val) ? $val : [$val];
                    $normalized[mb_strtolower($key)] = array_merge([mb_strtolower($key)], array_map('mb_strtolower', (array) $synList));
                }
            }
        }

        // Cerca match: la prima opzione che contiene una delle keyword vince
        foreach ($normalized as $canonical => $synonyms) {
            foreach ($synonyms as $kw) {
                if ($kw !== '' && (str_contains($clean, $kw) || $kw === $clean)) {
                    return $canonical;
                }
            }
        }

        return null;
    }

    /* ── Tipo: regex ───────────────────────────────────────────── */

    private function matchRegex(string $input, array $rule): ?string
    {
        $pattern = $rule['pattern'] ?? '';
        $group   = isset($rule['capture_group']) ? (int) $rule['capture_group'] : 0;

        if ($pattern === '') {
            return null;
        }

        // Aggiungi delimitatori se non presenti (UX-friendly)
        if (!preg_match('/^[\/#~%]/', $pattern)) {
            $pattern = '/' . $pattern . '/u';
        }

        try {
            if (@preg_match($pattern, $input, $m)) {
                return $m[$group] ?? $m[0] ?? null;
            }
        } catch (\Throwable) {
            return null;
        }
        return null;
    }

    /* ── Trasformazioni ────────────────────────────────────────── */

    private function applyTransform(mixed $value, ?string $transform): mixed
    {
        if (!is_scalar($value) || $transform === null || $transform === 'none') {
            return $value;
        }

        $str = (string) $value;

        return match ($transform) {
            'title_case' => mb_convert_case($str, MB_CASE_TITLE, 'UTF-8'),
            'lowercase'  => mb_strtolower($str, 'UTF-8'),
            'uppercase'  => mb_strtoupper($str, 'UTF-8'),
            'int'        => (int) $str,
            default      => $value,
        };
    }
}
