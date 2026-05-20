<?php

namespace App\Services\Flow\Modules;

use App\Services\Flow\FlowContext;
use App\Services\Flow\Module;
use App\Services\Flow\ModuleMeta;
use App\Services\Flow\ModuleResult;
use App\Services\UserSearchService;
use Illuminate\Support\Facades\Log;

/**
 * Cerca un utente nel database con matching fuzzy (LIKE + Levenshtein).
 *
 * Flusso a due fasi:
 *
 * Fase 1 (prima entrata): legge il nome dalla sessione (save_to configurato
 * dal modulo precedente, es. opponent_name) e cerca nel DB.
 *   - 0 risultati → salva come testo libero, porta "non_trovato"
 *   - 1 risultato → "Ho trovato {name}! È lui?" [Sì / No / Salta], wait
 *   - 2-3 risultati → mostra nomi come bottoni, wait
 *
 * Fase 2 (resume): processa la selezione dell'utente.
 *   - Conferma / selezione → salva opponent_user_id + opponent_name + opponent_phone,
 *     porta "confermato"
 *   - No / Salta → porta "salta"
 *
 * Se la ricerca va a vuoto (0 match), il nome originale resta in session.data
 * come opponent_name (testo libero, niente ELO tracking).
 */
class CercaUtenteModule extends Module
{
    public function meta(): ModuleMeta
    {
        return new ModuleMeta(
            key: 'cerca_utente',
            label: 'Cerca utente nel circolo',
            category: 'dati',
            description: 'Cerca un giocatore nel database per nome (fuzzy). Mostra i risultati come bottoni per conferma. Se non trovato, salva come testo libero.',
            configSchema: [
                'source' => [
                    'type'    => 'string',
                    'label'   => 'Chiave nome da cercare',
                    'default' => 'opponent_name',
                    'help'    => 'Chiave in session.data dove si trova il nome da cercare.',
                ],
                'max_results' => [
                    'type'    => 'int',
                    'label'   => 'Max risultati',
                    'default' => 3,
                ],
            ],
            icon: 'search',
        );
    }

    public function outputs(): array
    {
        return [
            'confermato'  => 'Utente confermato',
            'non_trovato' => 'Non trovato (testo libero)',
            'salta'       => 'Saltato',
        ];
    }

    public function execute(FlowContext $ctx): ModuleResult
    {
        $searchService = app(UserSearchService::class);

        // Fase 2: l'utente sta rispondendo alla selezione
        if ($ctx->resuming) {
            return $this->handleSelection($ctx);
        }

        // Fase 1: esegui la ricerca
        $source = (string) $this->cfg('source', 'opponent_name');
        $query  = (string) ($ctx->get($source) ?: $ctx->input);
        $max    = (int) $this->cfg('max_results', 3);

        if (trim($query) === '') {
            return ModuleResult::next('salta');
        }

        // Escludi l'utente corrente dalla ricerca
        $results = $searchService->search($query, $max, requirePhone: true);
        if ($ctx->user) {
            $results = $results->where('id', '!=', $ctx->user->id);
        }
        $results = $results->values();

        if ($results->isEmpty()) {
            // Nessun match → salva come testo libero
            return ModuleResult::next('non_trovato')->withData([
                'opponent_user_id' => null,
                'opponent_phone'   => null,
            ]);
        }

        // Salva risultati in sessione per fase 2
        $candidates = $results->take(3)->map(fn($u) => [
            'id'    => $u->id,
            'name'  => $u->name,
            'phone' => $u->phone,
        ])->values()->toArray();

        $ctx->set(['_search_candidates' => $candidates]);

        if (count($candidates) === 1) {
            $name = $candidates[0]['name'];
            return ModuleResult::wait(send: [[
                'type'    => 'buttons',
                'text'    => "Ho trovato {$name}! È il tuo avversario?",
                'buttons' => ['Sì, è lui', 'No', 'Salta'],
            ]]);
        }

        // 2-3 risultati → mostra come bottoni.
        // IMPORTANTE: Meta rifiuta i template con button title duplicati
        // (errore #131009). Disambiguiamo coi numeri di cellulare quando
        // i nomi collidono (es. due "Marco Rossi" nel circolo).
        $labels = $this->uniqueLabels($candidates);
        $labels[] = 'Nessuno di questi';

        return ModuleResult::wait(send: [[
            'type'    => 'buttons',
            'text'    => 'Quale di questi è il tuo avversario?',
            'buttons' => array_slice($labels, 0, 3), // max 3 bottoni WA
        ]]);
    }

    /**
     * Genera label uniche entro il limite WhatsApp di 20 char.
     *
     * Strategia:
     * - Se i nomi (truncated a 20) sono già tutti unici → li lascia così
     * - Altrimenti SOLO ai candidati in collisione aggiunge " ••XXXX"
     *   (ultime 4 cifre del telefono) come disambiguatore.
     *
     * @param  array<array{name:string, phone:?string}>  $candidates
     * @return string[]
     */
    private function uniqueLabels(array $candidates): array
    {
        $truncated = array_map(fn($c) => mb_substr(trim($c['name']), 0, 20), $candidates);
        $counts    = array_count_values($truncated);

        $out = [];
        foreach ($candidates as $i => $c) {
            $base = $truncated[$i];

            if (($counts[$base] ?? 0) <= 1) {
                $out[] = $base;
                continue;
            }

            // Collisione: appendi gli ultimi 4 digit del telefono
            $phone   = (string) ($c['phone'] ?? '');
            $digits  = preg_replace('/\D/', '', $phone);
            $last4   = $digits !== '' ? mb_substr($digits, -4) : '';
            $suffix  = $last4 !== '' ? ' ••' . $last4 : ' (' . ($i + 1) . ')';

            // Tronca il nome per fare spazio al suffisso
            $maxBaseLen = 20 - mb_strlen($suffix);
            $out[] = mb_substr($base, 0, max(0, $maxBaseLen)) . $suffix;
        }
        return $out;
    }

    private function handleSelection(FlowContext $ctx): ModuleResult
    {
        $candidates = (array) ($ctx->get('_search_candidates') ?? []);
        $input = mb_strtolower(trim($ctx->input));

        if (empty($candidates)) {
            return ModuleResult::next('salta');
        }

        // Singolo candidato: cerca "sì" / "no" / "salta"
        if (count($candidates) === 1) {
            if ($this->matchesYes($input)) {
                return $this->confirm($candidates[0]);
            }
            return ModuleResult::next('salta')->withData(['_search_candidates' => null]);
        }

        // Multi candidato: cerca match per nome o posizione
        if (str_contains($input, 'nessun')) {
            return ModuleResult::next('salta')->withData(['_search_candidates' => null]);
        }

        foreach ($candidates as $c) {
            $name = mb_strtolower($c['name']);
            if ($input === $name || str_contains($input, $name) || str_contains($name, $input)) {
                return $this->confirm($c);
            }
        }

        // Prova per posizione (1, 2, 3)
        if (preg_match('/^[1-3]$/', $input)) {
            $idx = (int) $input - 1;
            if (isset($candidates[$idx])) {
                return $this->confirm($candidates[$idx]);
            }
        }

        // Fallback: prendi il primo se è l'unico rimasto
        return ModuleResult::next('salta')->withData(['_search_candidates' => null]);
    }

    private function confirm(array $candidate): ModuleResult
    {
        return ModuleResult::next('confermato')->withData([
            'opponent_user_id'    => $candidate['id'],
            'opponent_name'       => $candidate['name'],
            'opponent_phone'      => $candidate['phone'],
            '_search_candidates'  => null,
        ]);
    }

    private function matchesYes(string $input): bool
    {
        $yesWords = ['sì', 'si', 'yes', 'ok', 'esatto', 'confermo', 'è lui', 'lei', 'proprio'];
        foreach ($yesWords as $w) {
            if (str_contains($input, $w)) return true;
        }
        return false;
    }
}
