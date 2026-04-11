<?php

namespace App\Services\Bot;

use App\Models\Booking;
use App\Models\BotFlowState;
use App\Models\BotSession;
use App\Models\PricingRule;
use App\Models\User;
use App\Services\CalendarService;
use App\Services\UserSearchService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Macchina a stati deterministica.
 *
 * REGOLA D'ORO: la logica di transizione è TUTTA qui.
 * L'AI (Gemini) viene invocata SOLO per generare il testo della risposta
 * e interpretare input ambigui (es. date in linguaggio naturale).
 *
 * Ogni metodo handle* restituisce un BotResponse.
 */
class StateHandler
{
    /** Flag per evitare ricorsione infinita nella classificazione AI. */
    private bool $aiClassificationAttempted = false;

    public function __construct(
        private readonly CalendarService   $calendar,
        private readonly TextGenerator     $textGenerator,
        private readonly UserSearchService $userSearch,
    ) {}

    /**
     * Punto di ingresso: smista al metodo corretto in base allo stato.
     */
    public function handle(BotSession $session, string $input, ?User $user): BotResponse
    {
        // Reset del flag AI per ogni nuovo messaggio in arrivo
        $this->aiClassificationAttempted = false;

        $stateValue = $session->state;
        $state      = BotState::tryFrom($stateValue);          // null se è un custom state
        $normalized = mb_strtolower(trim($input));

        // ── Stato custom (creato dal pannello, non in enum) ─────────────────
        if ($state === null) {
            return $this->handleGenericSimple($session, $input, $user, $stateValue);
        }

        // ── Parole chiave globali (solo utenti non in onboarding) ──────────
        if (!$state->isOnboarding()) {
            if ($this->isMenuKeyword($normalized)) {
                return BotResponse::make(
                    $this->textGenerator->rephrase('menu_ritorno', $session->persona()),
                    BotState::MENU,
                    $this->getButtons('MENU', ['Prenota campo', 'Trovami avversario', 'Sparapalline']),
                );
            }

            if ($user !== null && $this->isPrenotazioniKeyword($normalized)) {
                return $this->handleMostraPrenotazioni($session, $user);
            }

            if ($user !== null && $this->isFeedbackKeyword($normalized)) {
                return BotResponse::make(
                    $this->textGenerator->rephrase('chiedi_feedback_rating', $session->persona()),
                    BotState::FEEDBACK,
                    $this->getButtons('FEEDBACK', ['1', '2', '3', '4', '5']),
                );
            }

            if ($user !== null && $this->isProfiloKeyword($normalized)) {
                return BotResponse::make(
                    $this->textGenerator->rephrase('modifica_profilo_scelta', $session->persona()),
                    BotState::MODIFICA_PROFILO,
                    $this->getButtons('MODIFICA_PROFILO', ['Stato FIT', 'Livello gioco', 'Fascia oraria']),
                );
            }
        }

        // ── "Indietro" durante l'onboarding ────────────────────────────────
        if ($state->isOnboarding() && $state !== BotState::ONBOARD_NOME && $this->isIndietroKeyword($normalized)) {
            return $this->handleIndietroOnboarding($session, $state);
        }

        return match ($state) {
            BotState::NEW                => $this->handleNew($session, $input),
            BotState::ONBOARD_NOME       => $this->handleOnboardNome($session, $input),
            BotState::ONBOARD_FIT        => $this->handleOnboardFit($session, $input),
            BotState::ONBOARD_CLASSIFICA => $this->handleOnboardClassifica($session, $input),
            BotState::ONBOARD_LIVELLO    => $this->handleOnboardLivello($session, $input),
            BotState::ONBOARD_ETA        => $this->handleOnboardEta($session, $input),
            BotState::ONBOARD_SLOT_PREF  => $this->handleOnboardSlotPref($session, $input),
            BotState::ONBOARD_COMPLETO   => $this->handleOnboardCompleto($session, $input),
            BotState::MENU               => $this->handleMenu($session, $input, $user),
            BotState::ASK_OPPONENT       => $this->handleAskOpponent($session, $input, $user),
            BotState::SCEGLI_QUANDO      => $this->handleScegliQuando($session, $input),
            BotState::SCEGLI_DURATA      => $this->handleScegliDurata($session, $input),
            BotState::VERIFICA_SLOT      => $this->handleVerificaSlot($session, $input),
            BotState::PROPONI_SLOT       => $this->handleProponiSlot($session, $input),
            BotState::CONFERMA           => $this->handleConferma($session, $input),
            BotState::PAGAMENTO          => $this->handlePagamento($session, $input),
            BotState::CONFERMATO              => $this->handleConfermato($session, $input),
            BotState::ATTESA_MATCH            => $this->handleAttesaMatch($session, $input),
            BotState::RISPOSTA_MATCH          => $this->handleRispostaMatch($session, $input),
            BotState::CONFERMA_INVITO_OPP     => $this->handleConfermaInvitoOpponent($session, $input),
            BotState::GESTIONE_PRENOTAZIONI   => $this->handleSelezionaPrenotazione($session, $input, $user),
            BotState::AZIONE_PRENOTAZIONE     => $this->handleAzionePrenotazione($session, $input),
            BotState::MODIFICA_PROFILO        => $this->handleModificaProfilo($session, $input, $user),
            BotState::MODIFICA_RISPOSTA       => $this->handleModificaRisposta($session, $input),
            BotState::INSERISCI_RISULTATO     => $this->handleInserisciRisultato($session, $input),
            BotState::FEEDBACK                => $this->handleFeedback($session, $input),
            BotState::FEEDBACK_COMMENTO       => $this->handleFeedbackCommento($session, $input),
        };
    }

    /* ═══════════════════════════════════════════════════════════════
     *  ONBOARDING — Raccolta dati deterministici
     * ═══════════════════════════════════════════════════════════════ */

    private function handleNew(BotSession $session, string $input): BotResponse
    {
        // Lo stato NEW invia il saluto iniziale (già fatto in BotOrchestrator).
        // Se siamo qui, l'utente ha risposto al saluto → è il nome.
        return $this->handleOnboardNome($session, $input);
    }

    private function handleOnboardNome(BotSession $session, string $input): BotResponse
    {
        $name = $this->sanitizeName($input);

        if (empty($name)) {
            return BotResponse::make(
                $this->textGenerator->rephrase('nome_non_valido', $session->persona()),
                BotState::ONBOARD_NOME,
            );
        }

        $session->mergeProfile(['name' => $name]);

        return BotResponse::make(
            $this->textGenerator->rephrase('chiedi_fit', $session->persona(), ['name' => $name]),
            BotState::ONBOARD_FIT,
            $this->getButtons('ONBOARD_FIT', ["Sì, sono tesserato", "Non sono tesserato"]),
        );
    }

    private function handleOnboardFit(BotSession $session, string $input): BotResponse
    {
        $normalized = mb_strtolower(trim($input));

        // Controllo negativo PRIMA per evitare falsi positivi su "non sono tesserato"
        $isNotFit = $this->matchesNo($normalized)
            || str_contains($normalized, 'non sono')
            || str_contains($normalized, 'non ho')
            || str_contains($normalized, 'senza tessera')
            || str_contains($normalized, 'non tesserato');

        $isFit = !$isNotFit && ($this->matchesYes($normalized) || str_contains($normalized, 'tesserato'));

        if (!$isFit && !$isNotFit) {
            // Fallback AI: classifica l'input rispetto ai bottoni
            $aiMatch = $this->classifyWithAi($input, 'ONBOARD_FIT');
            if ($aiMatch !== null) {
                $aiNorm = mb_strtolower($aiMatch);
                $isNotFit = str_contains($aiNorm, 'non');
                $isFit = !$isNotFit;
            }
        }

        if (!$isFit && !$isNotFit) {
            return BotResponse::make(
                $this->textGenerator->rephrase('fit_non_capito', $session->persona()),
                BotState::ONBOARD_FIT,
                $this->getButtons('ONBOARD_FIT', ["Sì, sono tesserato", "Non sono tesserato"]),
            );
        }

        $session->mergeProfile(['is_fit' => $isFit]);

        if ($isFit) {
            return BotResponse::make(
                $this->textGenerator->rephrase('chiedi_classifica', $session->persona()),
                BotState::ONBOARD_CLASSIFICA,
            );
        }

        return BotResponse::make(
            $this->textGenerator->rephrase('chiedi_livello', $session->persona()),
            BotState::ONBOARD_LIVELLO,
            $this->getButtons('ONBOARD_LIVELLO', ['Neofita', 'Dilettante', 'Avanzato']),
        );
    }

    private function handleOnboardClassifica(BotSession $session, string $input): BotResponse
    {
        $rating = $this->parseClassificaFit($input);

        if ($rating === null) {
            return BotResponse::make(
                $this->textGenerator->rephrase('classifica_non_valida', $session->persona()),
                BotState::ONBOARD_CLASSIFICA,
            );
        }

        $session->mergeProfile(['fit_rating' => $rating]);

        return BotResponse::make(
            $this->textGenerator->rephrase('chiedi_eta', $session->persona()),
            BotState::ONBOARD_ETA,
        );
    }

    private function handleOnboardLivello(BotSession $session, string $input): BotResponse
    {
        $level = $this->parseLivello($input);

        // Fallback AI se il parser deterministico fallisce
        if ($level === null) {
            $aiMatch = $this->classifyWithAi($input, 'ONBOARD_LIVELLO');
            if ($aiMatch !== null) {
                $level = $this->parseLivello($aiMatch);
            }
        }

        if ($level === null) {
            return BotResponse::make(
                $this->textGenerator->rephrase('livello_non_valido', $session->persona()),
                BotState::ONBOARD_LIVELLO,
                $this->getButtons('ONBOARD_LIVELLO', ['Neofita', 'Dilettante', 'Avanzato']),
            );
        }

        $session->mergeProfile(['self_level' => $level]);

        return BotResponse::make(
            $this->textGenerator->rephrase('chiedi_eta', $session->persona()),
            BotState::ONBOARD_ETA,
        );
    }

    private function handleOnboardEta(BotSession $session, string $input): BotResponse
    {
        $age = $this->parseAge($input);

        if ($age === null) {
            return BotResponse::make(
                $this->textGenerator->rephrase('eta_non_valida', $session->persona()),
                BotState::ONBOARD_ETA,
            );
        }

        $session->mergeProfile(['age' => $age]);

        return BotResponse::make(
            $this->textGenerator->rephrase('chiedi_fascia_oraria', $session->persona()),
            BotState::ONBOARD_SLOT_PREF,
            $this->getButtons('ONBOARD_SLOT_PREF', ['Mattina', 'Pomeriggio', 'Sera']),
        );
    }

    private function handleOnboardSlotPref(BotSession $session, string $input): BotResponse
    {
        $slot = $this->parseFasciaOraria($input);

        // Fallback AI
        if ($slot === null) {
            $aiMatch = $this->classifyWithAi($input, 'ONBOARD_SLOT_PREF');
            if ($aiMatch !== null) {
                $slot = $this->parseFasciaOraria($aiMatch);
            }
        }

        if ($slot === null) {
            return BotResponse::make(
                $this->textGenerator->rephrase('fascia_non_valida', $session->persona()),
                BotState::ONBOARD_SLOT_PREF,
                $this->getButtons('ONBOARD_SLOT_PREF', ['Mattina', 'Pomeriggio', 'Sera']),
            );
        }

        $session->mergeProfile(['slot' => $slot]);

        $profile = $session->profile();

        return BotResponse::make(
            $this->textGenerator->rephrase('registrazione_completa', $session->persona(), [
                'name' => $profile['name'] ?? 'Giocatore',
            ]),
            BotState::ONBOARD_COMPLETO,
            $this->getButtons('ONBOARD_COMPLETO', ['Prenota campo', 'Trovami avversario', 'Sparapalline']),
        )->withProfileToSave($profile);
    }

    private function handleOnboardCompleto(BotSession $session, string $input): BotResponse
    {
        // L'utente ha appena completato la registrazione e sceglie un'azione.
        // Reindirizziamo al menu.
        return $this->handleMenuChoice($session, $input);
    }

    /* ═══════════════════════════════════════════════════════════════
     *  MENU — Scelta azione
     * ═══════════════════════════════════════════════════════════════ */

    private function handleMenu(BotSession $session, string $input, ?User $user): BotResponse
    {
        return $this->handleMenuChoice($session, $input);
    }

    private function handleMenuChoice(BotSession $session, string $input): BotResponse
    {
        $normalized = mb_strtolower(trim($input));

        // ⚠️ "Sparapalline" PRIMA di "trova" — evita che "trovare sparapalline" attivi matchmaking
        if (str_contains($normalized, 'sparapalline') || str_contains($normalized, 'spara palline')
            || str_contains($normalized, 'macchina') || str_contains($normalized, 'da solo')
            || str_contains($normalized, 'allenamento') || str_contains($normalized, 'allenarmi')) {
            $session->mergeData(['booking_type' => 'sparapalline']);

            return BotResponse::make(
                $this->textGenerator->rephrase('chiedi_quando_sparapalline', $session->persona()),
                BotState::SCEGLI_QUANDO,
            );
        }

        if (str_contains($normalized, 'avversario') && str_contains($normalized, 'già')
            || str_contains($normalized, 'prenota campo') || str_contains($normalized, 'prenota')
            || str_contains($normalized, 'ho un compagno') || str_contains($normalized, 'con un amico')) {
            // Reset eventuali dati avversario lasciati da una sessione precedente
            $session->mergeData([
                'booking_type'      => 'con_avversario',
                'opponent_user_id'  => null,
                'opponent_name'     => null,
                'opponent_phone'    => null,
                'opponent_search_results' => null,
            ]);

            return BotResponse::make(
                $this->textGenerator->rephrase('chiedi_avversario', $session->persona()),
                BotState::ASK_OPPONENT,
            );
        }

        if (str_contains($normalized, 'trovami') || str_contains($normalized, 'trova')
            || str_contains($normalized, 'cerca') || str_contains($normalized, 'matchmaking')
            || str_contains($normalized, 'avversario')) {
            $session->mergeData(['booking_type' => 'matchmaking']);

            return BotResponse::make(
                $this->textGenerator->rephrase('chiedi_quando_match', $session->persona()),
                BotState::SCEGLI_QUANDO,
            );
        }

        // Fallback AI: classifica l'input rispetto ai bottoni del menu
        $aiMatch = $this->classifyWithAi($input, 'MENU');
        if ($aiMatch !== null) {
            return $this->handleMenuChoice($session, $aiMatch);
        }

        // Input non riconosciuto: riproponi il menu
        return BotResponse::make(
            $this->textGenerator->rephrase('menu_non_capito', $session->persona()),
            BotState::MENU,
            $this->getButtons('MENU', ['Prenota campo', 'Trovami avversario', 'Sparapalline']),
        );
    }

    /* ═══════════════════════════════════════════════════════════════
     *  ASK_OPPONENT — Chi è l'avversario? (solo per "con_avversario")
     * ═══════════════════════════════════════════════════════════════ */

    private function handleAskOpponent(BotSession $session, string $input, ?User $user): BotResponse
    {
        $normalized = mb_strtolower(trim($input));

        // ── 1) "Salta" / "Non lo conoscete" → procedi senza tracking ELO
        if (in_array($normalized, ['salta', 'skip', 'non lo so', 'preferisco non dirlo', 'passo', 'no'], true)
            || str_contains($normalized, 'non è del circolo')
            || str_contains($normalized, 'non e del circolo')
            || str_contains($normalized, 'esterno')
            || str_contains($normalized, 'amico esterno')) {
            $session->mergeData([
                'opponent_user_id'        => null,
                'opponent_name'           => null,
                'opponent_phone'          => null,
                'opponent_search_results' => null,
            ]);

            return BotResponse::make(
                $this->textGenerator->rephrase('avversario_saltato', $session->persona()),
                BotState::SCEGLI_QUANDO,
            );
        }

        // ── 2) Conferma "Sì/No" su un match già proposto
        $pendingMatch = $session->getData('opponent_pending_confirm');
        if (is_array($pendingMatch) && isset($pendingMatch['user_id'])) {
            if ($this->matchesYes($normalized) || str_contains($normalized, 'è lui')
                || str_contains($normalized, 'e lui') || str_contains($normalized, 'è lei')
                || str_contains($normalized, 'esatto') || str_contains($normalized, 'corretto')) {

                $opponent = User::find($pendingMatch['user_id']);
                if ($opponent && (!$user || $opponent->id !== $user->id)) {
                    $session->mergeData([
                        'opponent_user_id'        => $opponent->id,
                        'opponent_name'           => $opponent->name,
                        'opponent_phone'          => $opponent->phone,
                        'opponent_pending_confirm'=> null,
                        'opponent_search_results' => null,
                    ]);

                    return BotResponse::make(
                        $this->textGenerator->rephrase('avversario_confermato', $session->persona(), [
                            'name' => $opponent->name,
                        ]),
                        BotState::SCEGLI_QUANDO,
                    );
                }
            }

            if ($this->matchesNo($normalized) || str_contains($normalized, 'non è') || str_contains($normalized, 'altro')) {
                // L'utente nega: chiedi di nuovo il nome
                $session->mergeData(['opponent_pending_confirm' => null]);

                return BotResponse::make(
                    $this->textGenerator->rephrase('avversario_riprova', $session->persona()),
                    BotState::ASK_OPPONENT,
                );
            }
            // Altrimenti: tratta l'input come nuova ricerca (cade nel blocco 3)
            $session->mergeData(['opponent_pending_confirm' => null]);
        }

        // ── 3) Selezione da lista risultati (input numerico o match per label)
        $previousResults = $session->getData('opponent_search_results');
        if (is_array($previousResults) && !empty($previousResults)) {
            // Match numerico (1, 2, 3)
            if (preg_match('/^([1-3])\b/', $normalized, $m)) {
                $idx = (int) $m[1] - 1;
                if (isset($previousResults[$idx])) {
                    return $this->confirmOpponentSelection($session, $previousResults[$idx], $user);
                }
            }
            // Match per nome esatto sui risultati proposti
            foreach ($previousResults as $r) {
                $rName = mb_strtolower($r['name'] ?? '');
                if ($rName !== '' && (str_contains($normalized, $rName) || str_contains($rName, $normalized))) {
                    return $this->confirmOpponentSelection($session, $r, $user);
                }
            }
            // Match "nessuno"/"non è in lista"
            if (str_contains($normalized, 'nessuno') || str_contains($normalized, 'non è in lista')
                || str_contains($normalized, 'non e in lista')) {
                $session->mergeData([
                    'opponent_user_id'        => null,
                    'opponent_name'           => trim($input),
                    'opponent_phone'          => null,
                    'opponent_search_results' => null,
                ]);
                return BotResponse::make(
                    $this->textGenerator->rephrase('avversario_esterno', $session->persona(), [
                        'name' => trim($input),
                    ]),
                    BotState::SCEGLI_QUANDO,
                );
            }
        }

        // ── 4) Input troppo corto → rifiuta
        $cleanInput = trim($input);
        if (mb_strlen($cleanInput) < 2) {
            return BotResponse::make(
                $this->textGenerator->rephrase('avversario_nome_corto', $session->persona()),
                BotState::ASK_OPPONENT,
            );
        }

        // ── 5) Ricerca fuzzy nel DB
        $results = $this->userSearch->search($cleanInput, 5, false);

        // Escludi il challenger stesso
        if ($user) {
            $results = $results->filter(fn(User $u) => $u->id !== $user->id)->values();
        }

        // ── 5a) Nessun match → salva come stringa libera
        if ($results->isEmpty()) {
            $session->mergeData([
                'opponent_user_id'        => null,
                'opponent_name'           => $cleanInput,
                'opponent_phone'          => null,
                'opponent_search_results' => null,
            ]);

            return BotResponse::make(
                $this->textGenerator->rephrase('avversario_non_trovato', $session->persona(), [
                    'name' => $cleanInput,
                ]),
                BotState::SCEGLI_QUANDO,
            );
        }

        // ── 5b) Match singolo → proponi conferma "È lui?"
        if ($results->count() === 1) {
            $only = $results->first();
            $session->mergeData([
                'opponent_pending_confirm' => [
                    'user_id' => $only->id,
                    'name'    => $only->name,
                ],
                'opponent_search_results' => null,
            ]);

            return BotResponse::make(
                $this->textGenerator->rephrase('avversario_conferma_uno', $session->persona(), [
                    'name' => $only->name,
                ]),
                BotState::ASK_OPPONENT,
                ['Sì, è lui', 'No, è un altro', 'Salta'],
            );
        }

        // ── 5c) Match multipli → mostra max 3 come bottoni
        $top = $results->take(3)->values();
        $serialized = $top->map(fn(User $u) => [
            'user_id' => $u->id,
            'name'    => $u->name,
            'phone'   => $u->phone,
        ])->toArray();

        $session->mergeData([
            'opponent_search_results' => $serialized,
            'opponent_pending_confirm' => null,
        ]);

        // Le label dei bottoni sono i nomi (max 20 char)
        $buttons = $top->map(function (User $u) {
            $label = $u->name;
            if (mb_strlen($label) > 20) {
                $label = mb_substr($label, 0, 19) . '…';
            }
            return $label;
        })->toArray();

        return BotResponse::make(
            $this->textGenerator->rephrase('avversario_lista', $session->persona()),
            BotState::ASK_OPPONENT,
            $buttons,
        );
    }

    /**
     * Conferma diretta della selezione (da lista) e passa a SCEGLI_QUANDO.
     * Usato quando l'utente sceglie esplicitamente uno dei risultati proposti.
     */
    private function confirmOpponentSelection(BotSession $session, array $selected, ?User $user): BotResponse
    {
        $opponent = User::find($selected['user_id'] ?? 0);

        if (!$opponent || ($user && $opponent->id === $user->id)) {
            return BotResponse::make(
                $this->textGenerator->rephrase('avversario_riprova', $session->persona()),
                BotState::ASK_OPPONENT,
            );
        }

        $session->mergeData([
            'opponent_user_id'        => $opponent->id,
            'opponent_name'           => $opponent->name,
            'opponent_phone'          => $opponent->phone,
            'opponent_search_results' => null,
            'opponent_pending_confirm'=> null,
        ]);

        return BotResponse::make(
            $this->textGenerator->rephrase('avversario_confermato', $session->persona(), [
                'name' => $opponent->name,
            ]),
            BotState::SCEGLI_QUANDO,
        );
    }

    /* ═══════════════════════════════════════════════════════════════
     *  CONFERMA_INVITO_OPP — L'avversario taggato conferma il link
     * ═══════════════════════════════════════════════════════════════ */

    private function handleConfermaInvitoOpponent(BotSession $session, string $input): BotResponse
    {
        $normalized      = mb_strtolower(trim($input));
        $challengerName  = $session->getData('opp_invite_challenger_name') ?? 'Un giocatore';
        $slot            = $session->getData('opp_invite_slot') ?? '';

        if ($this->matchesYes($normalized) || str_contains($normalized, 'confermo')
            || str_contains($normalized, 'è vero') || str_contains($normalized, 'e vero')
            || str_contains($normalized, 'esatto') || str_contains($normalized, 'sì')) {
            return BotResponse::make(
                $this->textGenerator->rephrase('opp_invite_confermato', $session->persona(), [
                    'challenger_name' => $challengerName,
                    'slot'            => $slot,
                ]),
                BotState::MENU,
                ['Prenota campo', 'Trovami avversario', 'Sparapalline'],
            )->withOpponentLinkConfirmed(true);
        }

        if ($this->matchesNo($normalized) || str_contains($normalized, 'non è vero')
            || str_contains($normalized, 'non e vero') || str_contains($normalized, 'sbagliato')
            || str_contains($normalized, 'errore') || str_contains($normalized, 'non sono')) {
            return BotResponse::make(
                $this->textGenerator->rephrase('opp_invite_rifiutato', $session->persona()),
                BotState::MENU,
                ['Prenota campo', 'Trovami avversario', 'Sparapalline'],
            )->withOpponentLinkRejected(true);
        }

        // Fallback AI
        $aiMatch = $this->classifyWithAi($input, 'CONFERMA_INVITO_OPP');
        if ($aiMatch !== null) {
            return $this->handleConfermaInvitoOpponent($session, $aiMatch);
        }

        // Non capito → ripropone
        return BotResponse::make(
            $this->textGenerator->rephrase('opp_invite_non_capito', $session->persona(), [
                'challenger_name' => $challengerName,
                'slot'            => $slot,
            ]),
            BotState::CONFERMA_INVITO_OPP,
            ['Sì, confermo', 'No, sbagliato'],
        );
    }

    /* ═══════════════════════════════════════════════════════════════
     *  GENERIC SIMPLE HANDLER — stati custom creati dal pannello
     * ═══════════════════════════════════════════════════════════════ */

    /**
     * Handler "universale" per stati DB-driven (stati custom creati dal flow editor).
     *
     * Funziona così:
     *  1. Carica la riga di bot_flow_states corrispondente
     *  2. Tenta di matchare l'input con la label di uno dei bottoni
     *  3. (Opzionale) classify AI come fallback
     *  4. Se match → transizione al target_state del bottone, applica eventuale side_effect
     *  5. Se no match → ripropone il messaggio + i bottoni (o messaggio fallback se settato)
     *
     * Vincoli sui bottoni: max 3, label max 20 char, target_state deve esistere
     * (validato a save-time dal BotFlowStateController).
     */
    private function handleGenericSimple(BotSession $session, string $input, ?User $user, string $stateValue): BotResponse
    {
        $flowState = BotFlowState::getCached($stateValue);

        // Stato non trovato in DB: torna al menu (sicurezza)
        if (!$flowState) {
            Log::warning('Generic simple handler: state not in DB, redirecting to MENU', [
                'state' => $stateValue,
                'phone' => $session->phone,
            ]);
            return BotResponse::make(
                $this->textGenerator->rephrase('menu_ritorno', $session->persona()),
                BotState::MENU,
                $this->getButtons('MENU', ['Prenota campo', 'Trovami avversario', 'Sparapalline']),
            );
        }

        $buttons      = $flowState->buttons ?? [];
        $messageKey   = $flowState->message_key;
        $fallbackKey  = $flowState->fallback_key ?: $messageKey;
        $buttonLabels = array_column($buttons, 'label');

        // ── 1) Match diretto: la label del bottone è contenuta nell'input (case-insensitive)
        $matched = $this->matchButtonByInput($buttons, $input);

        // ── 2) Fallback AI: solo se non matchato e ci sono bottoni
        if ($matched === null && !empty($buttons)) {
            $aiLabel = $this->classifyWithAi($input, $stateValue);
            if ($aiLabel !== null) {
                $matched = $this->matchButtonByLabel($buttons, $aiLabel);
            }
        }

        // ── 3) Match trovato: applica side_effect e transita
        if ($matched !== null) {
            $targetState = $matched['target_state'] ?? $stateValue;
            $sideEffect  = $matched['side_effect'] ?? null;
            $value       = $matched['value'] ?? null;

            // Salva il valore del bottone in sessione (utile per stati custom multi-step)
            if ($value !== null) {
                $session->mergeData(["custom_{$stateValue}_value" => $value]);
            }

            $response = BotResponse::make(
                $this->textGenerator->rephrase($messageKey, $session->persona()),
                $this->resolveTargetState($targetState),
                $this->getButtonsForCustomTarget($targetState),
            );

            return $this->applySideEffect($response, $sideEffect);
        }

        // ── 4) Nessun match: ripeti il messaggio (o fallback) coi bottoni
        return BotResponse::make(
            $this->textGenerator->rephrase($fallbackKey, $session->persona()),
            $stateValue,                       // resta sullo stato corrente
            $buttonLabels,                     // ripropone gli stessi bottoni
        );
    }

    /**
     * Matcha il primo bottone la cui label appare (case-insensitive) nell'input.
     */
    private function matchButtonByInput(array $buttons, string $input): ?array
    {
        $normInput = mb_strtolower(trim($input));
        if ($normInput === '') {
            return null;
        }

        foreach ($buttons as $btn) {
            $label = mb_strtolower($btn['label'] ?? '');
            if ($label === '') {
                continue;
            }
            if ($normInput === $label || str_contains($normInput, $label) || str_contains($label, $normInput)) {
                return $btn;
            }
        }
        return null;
    }

    private function matchButtonByLabel(array $buttons, string $label): ?array
    {
        $normLabel = mb_strtolower($label);
        foreach ($buttons as $btn) {
            if (mb_strtolower($btn['label'] ?? '') === $normLabel) {
                return $btn;
            }
        }
        return null;
    }

    /**
     * Risolve un nome di stato (string) nel tipo corretto da passare a BotResponse.
     * Se è un built-in enum case → restituisce l'enum. Altrimenti string custom.
     */
    private function resolveTargetState(string $targetValue): BotState|string
    {
        $enum = BotState::tryFrom($targetValue);
        return $enum ?? $targetValue;
    }

    /**
     * Restituisce le label dei bottoni per un dato target state (custom o built-in).
     * Permette di mostrare immediatamente i bottoni del NUOVO stato dopo la transizione.
     */
    private function getButtonsForCustomTarget(string $targetValue): array
    {
        // Built-in: prova a leggere dal DB (potrebbero essere stati editati)
        $flowState = BotFlowState::getCached($targetValue);
        if ($flowState && !empty($flowState->buttons)) {
            return array_column($flowState->buttons, 'label');
        }
        return [];
    }

    /**
     * Applica un side_effect (string) su una BotResponse, mappandolo
     * sul metodo with* corrispondente.
     *
     * Whitelist dei side_effect supportati. Aggiungere qui se ne servono di nuovi.
     */
    private function applySideEffect(BotResponse $response, ?string $sideEffect): BotResponse
    {
        if (!$sideEffect) {
            return $response;
        }

        return match ($sideEffect) {
            'calendarCheck'         => $response->withCalendarCheck(true),
            'paymentRequired'       => $response->withPaymentRequired(true),
            'bookingToCreate'       => $response->withBookingToCreate(true),
            'bookingToCancel'       => $response->withBookingToCancel(true),
            'matchmakingSearch'     => $response->withMatchmakingSearch(true),
            'matchAccepted'         => $response->withMatchAccepted(true),
            'matchRefused'          => $response->withMatchRefused(true),
            'matchResultToSave'     => $response->withMatchResultToSave(true),
            'feedbackToSave'        => $response->withFeedbackToSave(true),
            'opponentLinkConfirmed' => $response->withOpponentLinkConfirmed(true),
            'opponentLinkRejected'  => $response->withOpponentLinkRejected(true),
            default => $response,
        };
    }

    /**
     * Whitelist statica dei side_effect disponibili.
     * Esposta al frontend tramite endpoint API per popolare il dropdown del flow editor.
     */
    public static function availableSideEffects(): array
    {
        return [
            'calendarCheck'         => 'Verifica disponibilità calendario',
            'paymentRequired'       => 'Richiede pagamento online',
            'bookingToCreate'       => 'Crea prenotazione',
            'bookingToCancel'       => 'Cancella prenotazione',
            'matchmakingSearch'     => 'Cerca avversario (matchmaking)',
            'matchAccepted'         => 'Accetta sfida matchmaking',
            'matchRefused'          => 'Rifiuta sfida matchmaking',
            'matchResultToSave'     => 'Salva risultato partita',
            'feedbackToSave'        => 'Salva feedback utente',
            'opponentLinkConfirmed' => 'Conferma link avversario',
            'opponentLinkRejected'  => 'Rifiuta link avversario',
        ];
    }

    /* ═══════════════════════════════════════════════════════════════
     *  PRENOTAZIONE — Scelta data/ora e verifica
     * ═══════════════════════════════════════════════════════════════ */

    private function handleScegliQuando(BotSession $session, string $input): BotResponse
    {
        // Parser locale deterministico + fallback AI
        $parsed = $this->textGenerator->parseDateTime($input);

        if ($parsed === null) {
            Log::info('Date parse failed for input', ['input' => $input, 'state' => 'SCEGLI_QUANDO']);

            return BotResponse::make(
                $this->textGenerator->rephrase('data_non_capita', $session->persona()),
                BotState::SCEGLI_QUANDO,
            );
        }

        Log::info('Date parsed successfully', ['input' => $input, 'result' => $parsed]);

        $session->mergeData([
            'requested_date'     => $parsed['date'],
            'requested_time'     => $parsed['time'],
            'requested_raw'      => $input,
            'requested_friendly' => $parsed['friendly'],
        ]);

        // Controlla che la data non sia nel passato
        $requestedDt = \Carbon\Carbon::parse(
            $parsed['date'] . ' ' . ($parsed['time'] ?? '23:59'),
            'Europe/Rome'
        );

        if ($requestedDt->isPast()) {
            return BotResponse::make(
                $this->textGenerator->rephrase('data_nel_passato', $session->persona()),
                BotState::SCEGLI_QUANDO,
            );
        }

        $durations = PricingRule::availableDurations();
        $durationButtons = array_map(
            fn(int $m) => PricingRule::durationLabel($m),
            $durations
        );

        // Calcola tariffe per ogni durata disponibile
        $startTime = \Carbon\Carbon::parse(
            $session->getData('requested_date') . ' ' . ($session->getData('requested_time') ?? '08:00'),
            'Europe/Rome'
        );
        $tariffLines = [];
        foreach ($durations as $min) {
            $price = PricingRule::getPriceForSlot($startTime, $min);
            $tariffLines[] = '• ' . PricingRule::durationLabel($min) . ' → €' . number_format($price, 0);
        }
        $tariffe = implode("\n", $tariffLines);

        return BotResponse::make(
            $this->textGenerator->rephrase('chiedi_durata', $session->persona(), [
                'tariffe' => $tariffe,
            ]),
            BotState::SCEGLI_DURATA,
            $durationButtons,
        );
    }

    private function handleScegliDurata(BotSession $session, string $input): BotResponse
    {
        $normalized = mb_strtolower(trim($input));
        $available  = PricingRule::availableDurations();

        $parsed = null;
        if (preg_match('/\b1[,.]5\b|\bun\'ora e mezzo\b|\bora e mezza\b|\bun ora e mezzo\b/', $normalized)) {
            $parsed = 90;
        } elseif (preg_match('/\b2\s*ore\b|\bdue ore\b/', $normalized)) {
            $parsed = 120;
        } elseif (preg_match('/\b3\s*ore\b|\btre ore\b/', $normalized)) {
            $parsed = 180;
        } elseif (preg_match('/\b1\s*ora\b|\bun\'ora\b|\bun ora\b|\b1h\b/', $normalized)) {
            $parsed = 60;
        }

        $durationButtons = array_map(
            fn(int $m) => PricingRule::durationLabel($m),
            $available
        );

        // Rifiuta durate non configurate
        if ($parsed === null || !in_array($parsed, $available, true)) {
            return BotResponse::make(
                $this->textGenerator->rephrase('durata_non_capita', $session->persona()),
                BotState::SCEGLI_DURATA,
                $durationButtons,
            );
        }

        $session->mergeData(['requested_duration_minutes' => $parsed]);

        return BotResponse::make(
            $this->textGenerator->rephrase('verifico_disponibilita', $session->persona()),
            BotState::VERIFICA_SLOT,
        )->withCalendarCheck(true);
    }

    private function handleVerificaSlot(BotSession $session, string $input): BotResponse
    {
        // Questo stato viene raggiunto dall'orchestrator dopo il check calendar.
        // I dati di disponibilità sono in session->data['calendar_result'].
        $calendarResult = $session->getData('calendar_result');

        if ($calendarResult === null) {
            Log::error('VERIFICA_SLOT senza calendar_result', ['session' => $session->id]);

            return BotResponse::make(
                $this->textGenerator->rephrase('errore_generico', $session->persona()),
                BotState::SCEGLI_QUANDO,
            );
        }

        if ($calendarResult['available']) {
            $friendly      = $session->getData('requested_friendly') ?? 'l\'orario richiesto';
            $duration      = $session->getData('requested_duration_minutes') ?? 60;
            $price         = \App\Models\PricingRule::getPriceForSlot(
                \Carbon\Carbon::parse($session->getData('requested_date') . ' ' . ($session->getData('requested_time') ?? '08:00'), 'Europe/Rome'),
                $duration,
            );
            $durationLabel = \App\Models\PricingRule::durationLabel($duration);

            return BotResponse::make(
                $this->textGenerator->rephrase('slot_disponibile', $session->persona(), [
                    'slot'     => $friendly,
                    'duration' => $durationLabel,
                    'price'    => number_format($price, 0),
                ]),
                BotState::PROPONI_SLOT,
                $this->getButtons('PROPONI_SLOT', ['Sì, prenota', 'No, cambia orario']),
            );
        }

        // Non disponibile: mostra alternative
        $alternatives = $calendarResult['alternatives'] ?? [];

        if (empty($alternatives)) {
            return BotResponse::make(
                $this->textGenerator->rephrase('nessuna_alternativa', $session->persona()),
                BotState::SCEGLI_QUANDO,
            );
        }

        $altLabels = array_map(
            fn($a) => $a['label'] ?? "{$a['time']} (€{$a['price']})",
            array_slice($alternatives, 0, 3)  // Max 3 pulsanti WhatsApp
        );

        $session->mergeData(['alternatives' => $alternatives]);

        return BotResponse::make(
            $this->textGenerator->rephrase('slot_non_disponibile', $session->persona(), [
                'alternatives' => $altLabels,
            ]),
            BotState::PROPONI_SLOT,
            $altLabels,
        );
    }

    private function handleProponiSlot(BotSession $session, string $input): BotResponse
    {
        $normalized = mb_strtolower(trim($input));

        // "Sì, prenota" → conferma
        if ($this->matchesYes($normalized) || str_contains($normalized, 'prenota')) {
            $friendly    = $session->getData('requested_friendly') ?? 'l\'orario selezionato';
            $bookingType = $session->getData('booking_type') ?? 'con_avversario';

            $buttons = $bookingType === 'matchmaking'
                ? ['Cerca avversario', 'Annulla']
                : ['Paga online', 'Pago di persona', 'Annulla'];

            $duration      = $session->getData('requested_duration_minutes') ?? 60;
            $price         = \App\Models\PricingRule::getPriceForSlot(
                \Carbon\Carbon::parse(
                    ($session->getData('requested_date') ?? now()->format('Y-m-d')) . ' ' . ($session->getData('requested_time') ?? '08:00'),
                    'Europe/Rome'
                ),
                $duration,
            );
            $durationLabel = \App\Models\PricingRule::durationLabel($duration);

            return BotResponse::make(
                $this->textGenerator->rephrase('riepilogo_prenotazione', $session->persona(), [
                    'slot'         => $friendly,
                    'duration'     => $durationLabel,
                    'price'        => number_format($price, 0),
                    'booking_type' => $bookingType,
                ]),
                BotState::CONFERMA,
                $buttons,
            );
        }

        // Scelta alternativa
        $alternatives = $session->getData('alternatives') ?? [];
        foreach ($alternatives as $alt) {
            $label = mb_strtolower($alt['label'] ?? $alt['time'] ?? '');
            if (str_contains($normalized, $label) || str_contains($normalized, $alt['time'] ?? '---')) {
                $session->mergeData([
                    'requested_date'     => $alt['date'] ?? $session->getData('requested_date'),
                    'requested_time'     => $alt['time'],
                    'requested_friendly' => $alt['label'] ?? $alt['time'],
                ]);

                $friendly = $alt['label'] ?? $alt['time'];

                return BotResponse::make(
                    $this->textGenerator->rephrase('slot_disponibile', $session->persona(), [
                        'slot' => $friendly,
                    ]),
                    BotState::PROPONI_SLOT,
                    $this->getButtons('PROPONI_SLOT', ['Sì, prenota', 'No, cambia orario']),
                );
            }
        }

        // "No" o "cambia" → torna a scegli quando
        if ($this->matchesNo($normalized) || str_contains($normalized, 'cambia') || str_contains($normalized, 'altro')) {
            return BotResponse::make(
                $this->textGenerator->rephrase('chiedi_quando', $session->persona()),
                BotState::SCEGLI_QUANDO,
            );
        }

        // Non capito
        return BotResponse::make(
            $this->textGenerator->rephrase('proposta_non_capita', $session->persona()),
            BotState::PROPONI_SLOT,
            $this->getButtons('PROPONI_SLOT', ['Sì, prenota', 'No, cambia orario']),
        );
    }

    /* ═══════════════════════════════════════════════════════════════
     *  CONFERMA & PAGAMENTO
     * ═══════════════════════════════════════════════════════════════ */

    private function handleConferma(BotSession $session, string $input): BotResponse
    {
        $normalized  = mb_strtolower(trim($input));
        $bookingType = $session->getData('booking_type') ?? 'con_avversario';

        // ── Matchmaking branch ──────────────────────────────────────────
        if ($bookingType === 'matchmaking') {
            if (str_contains($normalized, 'annulla') || str_contains($normalized, 'indietro')) {
                return BotResponse::make(
                    $this->textGenerator->rephrase('prenotazione_annullata', $session->persona()),
                    BotState::MENU,
                    $this->getButtons('MENU', ['Prenota campo', 'Trovami avversario', 'Sparapalline']),
                );
            }

            // "Cerca avversario" o qualsiasi conferma → avvia matchmaking
            return BotResponse::make(
                $this->textGenerator->rephrase('cerca_avversario', $session->persona(), [
                    'slot' => $session->getData('requested_friendly') ?? '',
                ]),
                BotState::ATTESA_MATCH,
            )->withMatchmakingSearch(true);
        }

        // ── Flusso normale (con_avversario / sparapalline) ───────────────
        if (str_contains($normalized, 'annulla') || str_contains($normalized, 'indietro')) {
            return BotResponse::make(
                $this->textGenerator->rephrase('prenotazione_annullata', $session->persona()),
                BotState::MENU,
                $this->getButtons('MENU', ['Prenota campo', 'Trovami avversario', 'Sparapalline']),
            );
        }

        if (str_contains($normalized, 'online') || str_contains($normalized, 'paga')) {
            $session->mergeData(['payment_method' => 'online']);

            return BotResponse::make(
                $this->textGenerator->rephrase('link_pagamento', $session->persona()),
                BotState::PAGAMENTO,
            )->withPaymentRequired(true);
        }

        if (str_contains($normalized, 'persona') || str_contains($normalized, 'di persona')) {
            $session->mergeData(['payment_method' => 'in_loco']);

            return BotResponse::make(
                $this->textGenerator->rephrase('prenotazione_confermata', $session->persona(), [
                    'slot' => $session->getData('requested_friendly') ?? '',
                ]),
                BotState::CONFERMATO,
            )->withBookingToCreate(true);
        }

        // Conferma generica
        if ($this->matchesYes($normalized) || str_contains($normalized, 'conferma')) {
            return BotResponse::make(
                $this->textGenerator->rephrase('scegli_pagamento', $session->persona()),
                BotState::CONFERMA,
                $this->getButtons('CONFERMA', ['Paga online', 'Pago di persona', 'Annulla']),
            );
        }

        return BotResponse::make(
            $this->textGenerator->rephrase('conferma_non_capita', $session->persona()),
            BotState::CONFERMA,
            $this->getButtons('CONFERMA', ['Paga online', 'Pago di persona', 'Annulla']),
        );
    }

    private function handlePagamento(BotSession $session, string $input): BotResponse
    {
        // In un flusso reale, qui verificheresti il callback di pagamento.
        // Per ora gestiamo la conferma manuale.
        return BotResponse::make(
            $this->textGenerator->rephrase('prenotazione_confermata', $session->persona(), [
                'slot' => $session->getData('requested_friendly') ?? '',
            ]),
            BotState::CONFERMATO,
        )->withBookingToCreate(true);
    }

    private function handleConfermato(BotSession $session, string $input): BotResponse
    {
        // Qualunque messaggio dopo la conferma riporta al menu
        return BotResponse::make(
            $this->textGenerator->rephrase('menu_ritorno', $session->persona()),
            BotState::MENU,
            $this->getButtons('MENU', ['Prenota campo', 'Trovami avversario', 'Sparapalline']),
        );
    }

    /* ═══════════════════════════════════════════════════════════════
     *  INDIETRO ONBOARDING
     * ═══════════════════════════════════════════════════════════════ */

    private function handleIndietroOnboarding(BotSession $session, BotState $current): BotResponse
    {
        $persona = $session->persona();
        $profile = $session->profile();

        $prefix = $this->textGenerator->rephrase('indietro_onboarding', $persona) . "\n\n";

        return match ($current) {
            BotState::ONBOARD_FIT =>
                BotResponse::make(
                    $prefix . $this->textGenerator->rephrase('chiedi_nome_nuovo', $persona),
                    BotState::ONBOARD_NOME,
                ),

            BotState::ONBOARD_CLASSIFICA,
            BotState::ONBOARD_LIVELLO =>
                BotResponse::make(
                    $prefix . $this->textGenerator->rephrase('chiedi_fit', $persona, [
                        'name' => $profile['name'] ?? '',
                    ]),
                    BotState::ONBOARD_FIT,
                    $this->getButtons('ONBOARD_FIT', ['Sì, sono tesserato', 'Non sono tesserato']),
                ),

            BotState::ONBOARD_ETA =>
                ($profile['is_fit'] ?? false)
                    ? BotResponse::make(
                        $prefix . $this->textGenerator->rephrase('chiedi_classifica', $persona),
                        BotState::ONBOARD_CLASSIFICA,
                    )
                    : BotResponse::make(
                        $prefix . $this->textGenerator->rephrase('chiedi_livello', $persona),
                        BotState::ONBOARD_LIVELLO,
                        $this->getButtons('ONBOARD_LIVELLO', ['Neofita', 'Dilettante', 'Avanzato']),
                    ),

            BotState::ONBOARD_SLOT_PREF =>
                BotResponse::make(
                    $prefix . $this->textGenerator->rephrase('chiedi_eta', $persona),
                    BotState::ONBOARD_ETA,
                ),

            default =>
                BotResponse::make(
                    $prefix . $this->textGenerator->rephrase('chiedi_nome_nuovo', $persona),
                    BotState::ONBOARD_NOME,
                ),
        };
    }

    /* ═══════════════════════════════════════════════════════════════
     *  MODIFICA PROFILO (utenti registrati)
     * ═══════════════════════════════════════════════════════════════ */

    private function handleModificaProfilo(BotSession $session, string $input, ?User $user): BotResponse
    {
        $normalized = mb_strtolower(trim($input));
        $persona    = $session->persona();

        if (str_contains($normalized, 'fit') || str_contains($normalized, 'tessera')) {
            $session->mergeData(['update_field' => 'fit']);
            return BotResponse::make(
                $this->textGenerator->rephrase('chiedi_fit', $persona, [
                    'name' => $user?->name ?? '',
                ]),
                BotState::MODIFICA_RISPOSTA,
                $this->getButtons('ONBOARD_FIT', ['Sì, sono tesserato', 'Non sono tesserato']),
            );
        }

        if (str_contains($normalized, 'livello') || str_contains($normalized, 'gioco')) {
            if ($user?->is_fit) {
                $session->mergeData(['update_field' => 'classifica']);
                return BotResponse::make(
                    $this->textGenerator->rephrase('chiedi_classifica', $persona),
                    BotState::MODIFICA_RISPOSTA,
                );
            }
            $session->mergeData(['update_field' => 'livello']);
            return BotResponse::make(
                $this->textGenerator->rephrase('chiedi_livello', $persona),
                BotState::MODIFICA_RISPOSTA,
                $this->getButtons('ONBOARD_LIVELLO', ['Neofita', 'Dilettante', 'Avanzato']),
            );
        }

        if (str_contains($normalized, 'fascia') || str_contains($normalized, 'orario') || str_contains($normalized, 'slot')) {
            $session->mergeData(['update_field' => 'slot']);
            return BotResponse::make(
                $this->textGenerator->rephrase('chiedi_fascia_oraria', $persona),
                BotState::MODIFICA_RISPOSTA,
                $this->getButtons('ONBOARD_SLOT_PREF', ['Mattina', 'Pomeriggio', 'Sera']),
            );
        }

        // Fallback AI
        $aiMatch = $this->classifyWithAi($input, 'MODIFICA_PROFILO');
        if ($aiMatch !== null) {
            return $this->handleModificaProfilo($session, $aiMatch, $user);
        }

        // Non riconosciuto: riproponi le opzioni
        return BotResponse::make(
            $this->textGenerator->rephrase('modifica_profilo_scelta', $persona),
            BotState::MODIFICA_PROFILO,
            $this->getButtons('MODIFICA_PROFILO', ['Stato FIT', 'Livello gioco', 'Fascia oraria']),
        );
    }

    private function handleModificaRisposta(BotSession $session, string $input): BotResponse
    {
        $updateField = $session->getData('update_field');
        $persona     = $session->persona();
        $profileUpdate = [];

        switch ($updateField) {
            case 'fit':
                $normalized = mb_strtolower(trim($input));
                $isNotFit = $this->matchesNo($normalized)
                    || str_contains($normalized, 'non sono')
                    || str_contains($normalized, 'non ho');
                $isFit = !$isNotFit && ($this->matchesYes($normalized) || str_contains($normalized, 'tesserato'));

                if (!$isFit && !$isNotFit) {
                    return BotResponse::make(
                        $this->textGenerator->rephrase('fit_non_capito', $persona),
                        BotState::MODIFICA_RISPOSTA,
                        $this->getButtons('ONBOARD_FIT', ['Sì, sono tesserato', 'Non sono tesserato']),
                    );
                }
                $profileUpdate = ['is_fit' => $isFit, 'fit_rating' => null, 'self_level' => null];
                break;

            case 'classifica':
                $rating = $this->parseClassificaFit($input);
                if ($rating === null) {
                    return BotResponse::make(
                        $this->textGenerator->rephrase('classifica_non_valida', $persona),
                        BotState::MODIFICA_RISPOSTA,
                    );
                }
                $profileUpdate = ['fit_rating' => $rating];
                break;

            case 'livello':
                $level = $this->parseLivello($input);
                if ($level === null) {
                    return BotResponse::make(
                        $this->textGenerator->rephrase('livello_non_valido', $persona),
                        BotState::MODIFICA_RISPOSTA,
                        $this->getButtons('ONBOARD_LIVELLO', ['Neofita', 'Dilettante', 'Avanzato']),
                    );
                }
                $profileUpdate = ['self_level' => $level];
                break;

            case 'slot':
                $slot = $this->parseFasciaOraria($input);
                if ($slot === null) {
                    return BotResponse::make(
                        $this->textGenerator->rephrase('fascia_non_valida', $persona),
                        BotState::MODIFICA_RISPOSTA,
                        $this->getButtons('ONBOARD_SLOT_PREF', ['Mattina', 'Pomeriggio', 'Sera']),
                    );
                }
                $profileUpdate = ['slot' => $slot];
                break;

            default:
                return BotResponse::make(
                    $this->textGenerator->rephrase('menu_ritorno', $persona),
                    BotState::MENU,
                    $this->getButtons('MENU', ['Prenota campo', 'Trovami avversario', 'Sparapalline']),
                );
        }

        // Merge con il profilo in sessione e salva nel DB
        $merged = array_merge($session->profile(), $profileUpdate);
        $session->mergeData(['update_field' => null]);

        return BotResponse::make(
            $this->textGenerator->rephrase('profilo_aggiornato', $persona),
            BotState::MENU,
            $this->getButtons('MENU', ['Prenota campo', 'Trovami avversario', 'Sparapalline']),
        )->withProfileToSave($merged);
    }

    /* ═══════════════════════════════════════════════════════════════
     *  GESTIONE PRENOTAZIONI
     * ═══════════════════════════════════════════════════════════════ */

    /**
     * Chiamato dal keyword "prenotazioni": carica le prossime prenotazioni e le mostra.
     */
    private function handleMostraPrenotazioni(BotSession $session, User $user): BotResponse
    {
        $bookings = Booking::where('player1_id', $user->id)
            ->where('booking_date', '>=', now()->format('Y-m-d'))
            ->whereIn('status', ['confirmed', 'pending_match'])
            ->orderBy('booking_date')
            ->orderBy('start_time')
            ->take(3)
            ->get();

        if ($bookings->isEmpty()) {
            return BotResponse::make(
                $this->textGenerator->rephrase('nessuna_prenotazione', $session->persona()),
                BotState::MENU,
                $this->getButtons('MENU', ['Prenota campo', 'Trovami avversario', 'Sparapalline']),
            );
        }

        $dayNames   = ['Dom', 'Lun', 'Mar', 'Mer', 'Gio', 'Ven', 'Sab'];
        $monthNames = ['', 'gen', 'feb', 'mar', 'apr', 'mag', 'giu', 'lug', 'ago', 'set', 'ott', 'nov', 'dic'];

        $bookingsList = $bookings->map(function ($b) use ($dayNames, $monthNames) {
            $date  = Carbon::parse($b->booking_date);
            $label = $dayNames[$date->dayOfWeek] . ' ' . $date->day . ' ' . $monthNames[$date->month]
                   . ' ' . mb_substr($b->start_time, 0, 5);

            return [
                'id'    => $b->id,
                'date'  => $b->booking_date instanceof \Carbon\Carbon
                    ? $b->booking_date->format('Y-m-d')
                    : (string) $b->booking_date,
                'time'  => mb_substr($b->start_time, 0, 5),
                'gcal_id' => $b->gcal_event_id,
                'label' => $label,
            ];
        })->toArray();

        $session->mergeData(['bookings_list' => $bookingsList]);

        $buttons = array_map(fn($b) => mb_substr($b['label'], 0, 20), $bookingsList);

        return BotResponse::make(
            $this->textGenerator->rephrase('scegli_prenotazione', $session->persona()),
            BotState::GESTIONE_PRENOTAZIONI,
            $buttons,
        );
    }

    /**
     * Utente è in GESTIONE_PRENOTAZIONI: ha selezionato una prenotazione dalla lista.
     */
    private function handleSelezionaPrenotazione(BotSession $session, string $input, ?User $user): BotResponse
    {
        $normalized   = mb_strtolower(trim($input));
        $bookingsList = $session->getData('bookings_list') ?? [];

        // Cerca la prenotazione selezionata per label
        $selected = null;
        foreach ($bookingsList as $b) {
            if (str_contains($normalized, mb_strtolower($b['label'])) ||
                str_contains(mb_strtolower($b['label']), $normalized)) {
                $selected = $b;
                break;
            }
        }

        // Fallback: se non trova per label, prova a matchare per orario (es. "17:00")
        if ($selected === null) {
            foreach ($bookingsList as $b) {
                if (str_contains($normalized, $b['time'])) {
                    $selected = $b;
                    break;
                }
            }
        }

        if ($selected === null) {
            // Non capito — ripropone la lista
            $buttons = array_map(fn($b) => mb_substr($b['label'], 0, 20), array_slice($bookingsList, 0, 3));

            return BotResponse::make(
                $this->textGenerator->rephrase('scegli_prenotazione', $session->persona()),
                BotState::GESTIONE_PRENOTAZIONI,
                $buttons,
            );
        }

        $session->mergeData(['selected_booking_id' => $selected['id']]);

        $slotFriendly = $selected['label'];

        return BotResponse::make(
            $this->textGenerator->rephrase('azione_prenotazione', $session->persona(), [
                'slot' => $slotFriendly,
            ]),
            BotState::AZIONE_PRENOTAZIONE,
            $this->getButtons('AZIONE_PRENOTAZIONE', ['Modifica orario', 'Cancella', 'Torna al menu']),
        );
    }

    /**
     * Utente è in AZIONE_PRENOTAZIONE: sceglie cosa fare con la prenotazione selezionata.
     */
    private function handleAzionePrenotazione(BotSession $session, string $input): BotResponse
    {
        $normalized = mb_strtolower(trim($input));

        if (str_contains($normalized, 'modifica') || str_contains($normalized, 'sposta') || str_contains($normalized, 'cambia')) {
            // Salva l'ID della prenotazione da modificare e vai a SCEGLI_QUANDO
            $bookingId = $session->getData('selected_booking_id');
            $session->mergeData(['editing_booking_id' => $bookingId]);

            return BotResponse::make(
                $this->textGenerator->rephrase('prenotazione_modifica_quando', $session->persona()),
                BotState::SCEGLI_QUANDO,
            );
        }

        if (str_contains($normalized, 'cancella') || str_contains($normalized, 'elimina') || str_contains($normalized, 'annulla')) {
            return BotResponse::make(
                $this->textGenerator->rephrase('prenotazione_cancellata_ok', $session->persona()),
                BotState::MENU,
                $this->getButtons('MENU', ['Prenota campo', 'Trovami avversario', 'Sparapalline']),
            )->withBookingToCancel(true);
        }

        // "Torna al menu" o qualsiasi altro input
        return BotResponse::make(
            $this->textGenerator->rephrase('menu_ritorno', $session->persona()),
            BotState::MENU,
            $this->getButtons('MENU', ['Prenota campo', 'Trovami avversario', 'Sparapalline']),
        );
    }

    /* ═══════════════════════════════════════════════════════════════
     *  MATCHMAKING
     * ═══════════════════════════════════════════════════════════════ */

    private function handleAttesaMatch(BotSession $session, string $input): BotResponse
    {
        $normalized = mb_strtolower(trim($input));

        // L'utente può annullare l'attesa
        if (str_contains($normalized, 'annulla') || $this->isMenuKeyword($normalized)) {
            return BotResponse::make(
                $this->textGenerator->rephrase('menu_ritorno', $session->persona()),
                BotState::MENU,
                $this->getButtons('MENU', ['Prenota campo', 'Trovami avversario', 'Sparapalline']),
            );
        }

        // Qualsiasi altro messaggio: ricorda che stiamo aspettando
        return BotResponse::make(
            $this->textGenerator->rephrase('matchmaking_attesa', $session->persona()),
            BotState::ATTESA_MATCH,
        );
    }

    private function handleRispostaMatch(BotSession $session, string $input): BotResponse
    {
        $normalized      = mb_strtolower(trim($input));
        $invitedSlot     = $session->getData('invited_slot') ?? '';
        $challengerName  = $session->getData('invited_by_name') ?? 'Un giocatore';

        if ($this->matchesYes($normalized) || str_contains($normalized, 'accett')) {
            return BotResponse::make(
                $this->textGenerator->rephrase('match_accettato_opponent', $session->persona(), [
                    'slot' => $invitedSlot,
                ]),
                BotState::CONFERMATO,
            )->withMatchAccepted(true);
        }

        if ($this->matchesNo($normalized) || str_contains($normalized, 'rifiut') || str_contains($normalized, 'non posso')) {
            return BotResponse::make(
                $this->textGenerator->rephrase('match_rifiutato_opponent', $session->persona()),
                BotState::MENU,
                $this->getButtons('MENU', ['Prenota campo', 'Trovami avversario', 'Sparapalline']),
            )->withMatchRefused(true);
        }

        // Fallback AI
        $aiMatch = $this->classifyWithAi($input, 'RISPOSTA_MATCH');
        if ($aiMatch !== null) {
            return $this->handleRispostaMatch($session, $aiMatch);
        }

        // Non capito: riproponi l'invito
        return BotResponse::make(
            $this->textGenerator->rephrase('invito_match', $session->persona(), [
                'opponent_name'   => '',
                'challenger_name' => $challengerName,
                'slot'            => $invitedSlot,
            ]),
            BotState::RISPOSTA_MATCH,
            $this->getButtons('RISPOSTA_MATCH', ['Accetta', 'Rifiuta']),
        );
    }

    /* ═══════════════════════════════════════════════════════════════
     *  RISULTATI & FEEDBACK
     * ═══════════════════════════════════════════════════════════════ */

    private function handleInserisciRisultato(BotSession $session, string $input): BotResponse
    {
        $normalized = mb_strtolower(trim($input));
        $slot       = $session->getData('result_slot') ?? 'la partita';

        // Partita non giocata
        if (
            str_contains($normalized, 'non giocata') ||
            str_contains($normalized, 'annullata') ||
            str_contains($normalized, 'non si è') ||
            str_contains($normalized, 'non si e')
        ) {
            $session->mergeData(['result_outcome' => 'no_show', 'result_score' => null]);

            return BotResponse::make(
                $this->textGenerator->rephrase('risultato_non_giocata', $session->persona()),
                BotState::MENU,
                $this->getButtons('MENU', ['Prenota campo', 'Trovami avversario', 'Sparapalline']),
            )->withMatchResultToSave(true);
        }

        $won  = str_contains($normalized, 'vinto') || str_contains($normalized, 'ho vin');
        $lost = str_contains($normalized, 'perso') || str_contains($normalized, 'ho pers');

        if (!$won && !$lost) {
            return BotResponse::make(
                $this->textGenerator->rephrase('risultato_non_capito', $session->persona()),
                BotState::INSERISCI_RISULTATO,
                $this->getButtons('INSERISCI_RISULTATO', ['Ho vinto', 'Ho perso', 'Non giocata']),
            );
        }

        // Estrai punteggio se presente (es. "6-4 6-2", "7-6 3-6 6-4")
        preg_match_all('/\b(\d{1,2})[-\/](\d{1,2})\b/', $input, $m);
        $score = !empty($m[0]) ? implode(' ', $m[0]) : null;

        $session->mergeData([
            'result_outcome' => $won ? 'won' : 'lost',
            'result_score'   => $score,
        ]);

        // Dopo il risultato, chiedi feedback
        return BotResponse::make(
            $this->textGenerator->rephrase('risultato_ricevuto', $session->persona())
                . "\n\n" . $this->textGenerator->rephrase('feedback_dopo_partita', $session->persona()),
            BotState::FEEDBACK,
            $this->getButtons('FEEDBACK', ['1', '2', '3', '4', '5']),
        )->withMatchResultToSave(true);
    }

    private function handleFeedback(BotSession $session, string $input): BotResponse
    {
        // Step 1: raccolta rating (1-5)
        $rating = $this->parseRating($input);

        if ($rating === null) {
            return BotResponse::make(
                $this->textGenerator->rephrase('feedback_rating_non_valido', $session->persona()),
                BotState::FEEDBACK,
                $this->getButtons('FEEDBACK', ['1', '2', '3', '4', '5']),
            );
        }

        $session->mergeData(['feedback_rating' => $rating]);

        return BotResponse::make(
            $this->textGenerator->rephrase('chiedi_feedback_commento', $session->persona()),
            BotState::FEEDBACK_COMMENTO,
        );
    }

    private function handleFeedbackCommento(BotSession $session, string $input): BotResponse
    {
        $normalized = mb_strtolower(trim($input));

        // "no", "skip", "niente" → salva senza commento
        $skip = in_array($normalized, ['no', 'skip', 'niente', 'nulla', 'passo', 'salta'], true);
        $comment = $skip ? null : trim($input);

        $session->mergeData(['feedback_comment' => $comment]);

        return BotResponse::make(
            $this->textGenerator->rephrase('feedback_ricevuto', $session->persona()),
            BotState::MENU,
            $this->getButtons('MENU', ['Prenota campo', 'Trovami avversario', 'Sparapalline']),
        )->withFeedbackToSave(true);
    }

    private function parseRating(string $input): ?int
    {
        $normalized = mb_strtolower(trim($input));

        // Numero diretto
        if (preg_match('/\b([1-5])\b/', $normalized, $m)) {
            return (int) $m[1];
        }

        // Parole
        $map = [
            'uno' => 1, 'una' => 1,
            'due' => 2,
            'tre' => 3,
            'quattro' => 4,
            'cinque' => 5,
        ];

        foreach ($map as $word => $val) {
            if (str_contains($normalized, $word)) {
                return $val;
            }
        }

        // Stelle/emoji
        $stars = substr_count($input, '⭐') + substr_count($input, '★') + substr_count($input, '🌟');
        if ($stars >= 1 && $stars <= 5) {
            return $stars;
        }

        return null;
    }

    private function isFeedbackKeyword(string $input): bool
    {
        return in_array($input, ['feedback', 'valuta', 'vota', 'recensione', 'opinione'], true)
            || str_contains($input, 'lascia feedback')
            || str_contains($input, 'dai feedback');
    }

    /* ═══════════════════════════════════════════════════════════════
     *  PARSER — Validazione input deterministici
     * ═══════════════════════════════════════════════════════════════ */

    private function sanitizeName(string $input): ?string
    {
        // L'apostrofo deve essere preceduto da \ se la stringa è racchiusa tra ' '
        $clean = preg_replace('/[^\p{L}\s\'-]/u', '', trim($input));
        $clean = preg_replace('/\s+/', ' ', $clean);

        if (empty($clean) || mb_strlen($clean) < 2 || mb_strlen($clean) > 60) {
            return null;
        }

        return mb_convert_case($clean, MB_CASE_TITLE, 'UTF-8');
    }

    private function parseClassificaFit(string $input): ?string
    {
        $clean = mb_strtolower(trim($input));

        // NC (non classificato)
        if (in_array($clean, ['nc', 'non classificato', 'n.c.', 'n.c'])) {
            return 'NC';
        }

        // Classifiche FIT: 4.1, 4.2, 4.3, 4.4, 4.5, 4.6, 3.1, 3.2, ... 1.1
        if (preg_match('/^([1-4])[.,]([1-6])$/', $clean, $m)) {
            return "{$m[1]}.{$m[2]}";
        }

        // Categorie storiche: prima, seconda, terza, quarta
        $categories = [
            'prima'  => '1', 'seconda' => '2',
            'terza'  => '3', 'quarta'  => '4',
        ];

        foreach ($categories as $word => $cat) {
            if (str_contains($clean, $word)) {
                return "{$cat}.1"; // Default a .1 se non specificato
            }
        }

        return null;
    }

    private function parseLivello(string $input): ?string
    {
        $clean = mb_strtolower(trim($input));

        $map = [
            'neofita'     => 'neofita',
            'principiante' => 'neofita',
            'inizio'      => 'neofita',
            'dilettante'  => 'dilettante',
            'intermedio'  => 'dilettante',
            'medio'       => 'dilettante',
            'avanzato'    => 'avanzato',
            'esperto'     => 'avanzato',
            'buono'       => 'avanzato',
        ];

        foreach ($map as $keyword => $level) {
            if (str_contains($clean, $keyword)) {
                return $level;
            }
        }

        return null;
    }

    private function parseAge(string $input): ?int
    {
        // Estrai il primo numero trovato
        if (preg_match('/(\d+)/', trim($input), $m)) {
            $age = (int) $m[1];

            if ($age >= 5 && $age <= 99) {
                return $age;
            }
        }

        return null;
    }

    private function parseFasciaOraria(string $input): ?string
    {
        $clean = mb_strtolower(trim($input));

        $map = [
            'mattina'     => 'mattina',
            'mattino'     => 'mattina',
            'presto'      => 'mattina',
            'pomeriggio'  => 'pomeriggio',
            'primo pom'   => 'pomeriggio',
            'sera'        => 'sera',
            'serale'      => 'sera',
            'tardi'       => 'sera',
            'dopo cena'   => 'sera',
        ];

        foreach ($map as $keyword => $slot) {
            if (str_contains($clean, $keyword)) {
                return $slot;
            }
        }

        return null;
    }

    private function isMenuKeyword(string $input): bool
    {
        return in_array($input, ['menu', 'aiuto', 'help', 'home', 'start', 'ricomincia', '0', 'torna'], true)
            || str_contains($input, 'torna al menu');
    }

    private function isPrenotazioniKeyword(string $input): bool
    {
        return str_contains($input, 'prenotazion')
            || str_contains($input, 'mie prenotaz')
            || $input === 'booking';
    }

    private function isProfiloKeyword(string $input): bool
    {
        return str_contains($input, 'profilo')
            || str_contains($input, 'modifica profilo')
            || str_contains($input, 'aggiorna profilo')
            || $input === 'impostazioni';
    }

    private function isIndietroKeyword(string $input): bool
    {
        return in_array($input, ['indietro', 'back', 'torna', 'annulla', 'precedente'], true)
            || str_contains($input, 'torna indietro')
            || str_contains($input, 'vai indietro');
    }

    private function matchesYes(string $input): bool
    {
        return (bool) preg_match('/^(s[ìi]|ok|certo|va bene|perfetto|assolutamente|esatto)\b/i', $input);
    }

    private function matchesNo(string $input): bool
    {
        return (bool) preg_match('/^(no|nah|nope|non|neanche)\b/i', $input);
    }

    /**
     * Legge le label dei bottoni dal DB (BotFlowState), con fallback ai valori hardcoded.
     */
    private function getButtons(string $state, array $default): array
    {
        $flowState = BotFlowState::getCached($state);

        if ($flowState && !empty($flowState->buttons)) {
            return $flowState->buttonLabels();
        }

        return $default;
    }

    /**
     * Classifica l'input utente tramite AI quando il matching deterministico fallisce.
     * Restituisce la label del bottone matchato, o null.
     * Usa un flag per evitare ricorsione infinita.
     */
    private function classifyWithAi(string $input, string $state): ?string
    {
        // Evita ricorsione: se già tentato AI in questo ciclo, skip
        if ($this->aiClassificationAttempted) {
            return null;
        }

        $flowState = BotFlowState::getCached($state);

        if (!$flowState || empty($flowState->buttons)) {
            return null;
        }

        $this->aiClassificationAttempted = true;

        $labels = $flowState->buttonLabels();
        $index = $this->textGenerator->classifyInput($input, $labels);

        if ($index !== null) {
            return $labels[$index];
        }

        return null;
    }
}
