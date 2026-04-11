<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Ricerca utenti con matching tollerante (LIKE + Levenshtein).
 *
 * Condiviso tra:
 *  - pannello admin (autocomplete giocatori)
 *  - bot (associazione avversario in prenotazione "con_avversario")
 */
class UserSearchService
{
    /**
     * Trova utenti più simili al termine cercato.
     * Restituisce una Collection ordinata per rilevanza (più rilevante prima).
     *
     * @param  string  $query    Stringa libera (nome, cognome, telefono...)
     * @param  int     $limit    Numero massimo di risultati (default 10)
     * @param  bool    $requirePhone  Se true, esclude utenti senza phone
     * @return Collection<int, User>
     */
    public function search(string $query, int $limit = 10, bool $requirePhone = false): Collection
    {
        $q = trim($query);
        if ($q === '') {
            return collect();
        }

        $normalized = $this->normalize($q);

        // Step 1 — match SQL veloce (LIKE su tutte le parole)
        $tokens = preg_split('/\s+/', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $builder = User::query()->where('is_admin', false);

        if ($requirePhone) {
            $builder->whereNotNull('phone');
        }

        // Match phone esatto/parziale → priorità massima
        if (preg_match('/\d/', $q)) {
            $builder->where(function ($w) use ($q, $tokens) {
                $w->where('phone', 'like', '%' . preg_replace('/\D/', '', $q) . '%');
                foreach ($tokens as $t) {
                    $w->orWhere('name', 'like', "%{$t}%");
                }
            });
        } else {
            $builder->where(function ($w) use ($tokens) {
                foreach ($tokens as $t) {
                    $w->orWhere('name', 'like', "%{$t}%");
                }
            });
        }

        $candidates = $builder->limit($limit * 4)->get();

        if ($candidates->isEmpty()) {
            // Fallback: nessun match LIKE — prova fuzzy su tutti gli utenti
            // ma solo se la query è plausibilmente un nome
            if (mb_strlen($normalized) >= 3 && !preg_match('/\d/', $normalized)) {
                $candidates = User::query()
                    ->where('is_admin', false)
                    ->when($requirePhone, fn($q) => $q->whereNotNull('phone'))
                    ->limit(200)
                    ->get();
            } else {
                return collect();
            }
        }

        // Step 2 — calcola score per ogni candidato (più alto = più rilevante)
        $scored = $candidates->map(function (User $user) use ($normalized, $tokens) {
            $score = $this->scoreMatch($user, $normalized, $tokens);
            return ['user' => $user, 'score' => $score];
        })
        ->filter(fn($row) => $row['score'] > 0)
        ->sortByDesc('score')
        ->values();

        return $scored
            ->take($limit)
            ->map(fn($row) => $row['user']);
    }

    /**
     * Restituisce il miglior match singolo solo se è "abbastanza certo".
     * Usato dal bot per evitare di proporre conferma quando il match è ambiguo.
     */
    public function bestMatchOrNull(string $query, bool $requirePhone = false): ?User
    {
        $results = $this->search($query, 3, $requirePhone);

        if ($results->isEmpty()) {
            return null;
        }

        // Logica anti-ambiguità: il primo risultato deve essere significativamente
        // migliore del secondo (gap > 30%) per essere considerato "certo".
        if ($results->count() === 1) {
            return $results->first();
        }

        $first  = $results->first();
        $second = $results->skip(1)->first();

        $firstScore  = $this->scoreMatch($first,  $this->normalize($query), $this->tokens($query));
        $secondScore = $this->scoreMatch($second, $this->normalize($query), $this->tokens($query));

        if ($secondScore === 0) {
            return $first;
        }

        return ($firstScore - $secondScore) / max($firstScore, 1) > 0.3
            ? $first
            : null;
    }

    /* ───────── Internals ───────── */

    private function scoreMatch(User $user, string $normalizedQuery, array $tokens): int
    {
        $name = $this->normalize($user->name ?? '');
        if ($name === '') {
            return 0;
        }

        $score = 0;

        // Match esatto sull'intera stringa
        if ($name === $normalizedQuery) {
            $score += 1000;
        }

        // Substring match
        if (str_contains($name, $normalizedQuery)) {
            $score += 500;
        }

        // Match parola per parola
        foreach ($tokens as $token) {
            if (strlen($token) < 2) {
                continue;
            }
            if (str_contains($name, $token)) {
                $score += 100;
            }
            // Match prefisso parola (es. "mar" → "mario")
            foreach (explode(' ', $name) as $namePart) {
                if (str_starts_with($namePart, $token)) {
                    $score += 50;
                }
            }
        }

        // Levenshtein bonus se la stringa è abbastanza corta
        if (strlen($name) <= 60 && strlen($normalizedQuery) <= 60) {
            $distance = levenshtein($normalizedQuery, $name);
            $maxLen   = max(strlen($normalizedQuery), strlen($name));
            if ($maxLen > 0) {
                $similarity = 1 - ($distance / $maxLen);
                if ($similarity > 0.6) {
                    $score += (int) ($similarity * 100);
                }
            }
        }

        // Phone match diretto
        if (preg_match('/\d/', $normalizedQuery)) {
            $digits = preg_replace('/\D/', '', $normalizedQuery);
            if ($digits && $user->phone && str_contains($user->phone, $digits)) {
                $score += 800;
            }
        }

        return $score;
    }

    private function normalize(string $s): string
    {
        $s = mb_strtolower(trim($s), 'UTF-8');
        // Rimuovi accenti
        $s = strtr($s, [
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a',
            'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
            'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
            'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o',
            'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c', 'ñ' => 'n',
        ]);
        // Unifica spazi
        $s = preg_replace('/\s+/', ' ', $s);
        return $s ?? '';
    }

    private function tokens(string $s): array
    {
        return preg_split('/\s+/', $this->normalize($s), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }
}
