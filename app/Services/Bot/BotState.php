<?php

namespace App\Services\Bot;

/**
 * Macchina a stati deterministica del bot.
 * Ogni stato sa quali transizioni sono lecite.
 */
enum BotState: string
{
    /* ── Onboarding ── */
    case NEW                = 'NEW';
    case ONBOARD_NOME       = 'ONBOARD_NOME';
    case ONBOARD_FIT        = 'ONBOARD_FIT';
    case ONBOARD_CLASSIFICA = 'ONBOARD_CLASSIFICA';
    case ONBOARD_LIVELLO    = 'ONBOARD_LIVELLO';
    case ONBOARD_ETA        = 'ONBOARD_ETA';
    case ONBOARD_SLOT_PREF  = 'ONBOARD_SLOT_PREF';
    case ONBOARD_COMPLETO   = 'ONBOARD_COMPLETO';

    /* ── Menu principale ── */
    case MENU = 'MENU';

    /* ── Flusso prenotazione ── */
    case SCEGLI_QUANDO  = 'SCEGLI_QUANDO';
    case VERIFICA_SLOT  = 'VERIFICA_SLOT';
    case PROPONI_SLOT   = 'PROPONI_SLOT';
    case CONFERMA       = 'CONFERMA';
    case PAGAMENTO      = 'PAGAMENTO';
    case CONFERMATO     = 'CONFERMATO';

    /* ── Matchmaking ── */
    case ATTESA_MATCH = 'ATTESA_MATCH';

    /* ── Gestione prenotazioni ── */
    case GESTIONE_PRENOTAZIONI = 'GESTIONE_PRENOTAZIONI';
    case AZIONE_PRENOTAZIONE   = 'AZIONE_PRENOTAZIONE';

    /**
     * Transizioni valide da ogni stato.
     *
     * @return BotState[]
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::NEW                => [self::ONBOARD_NOME],
            self::ONBOARD_NOME       => [self::ONBOARD_FIT],
            self::ONBOARD_FIT        => [self::ONBOARD_CLASSIFICA, self::ONBOARD_LIVELLO],
            self::ONBOARD_CLASSIFICA => [self::ONBOARD_ETA],
            self::ONBOARD_LIVELLO    => [self::ONBOARD_ETA],
            self::ONBOARD_ETA        => [self::ONBOARD_SLOT_PREF],
            self::ONBOARD_SLOT_PREF  => [self::ONBOARD_COMPLETO],
            self::ONBOARD_COMPLETO   => [self::MENU, self::SCEGLI_QUANDO, self::ATTESA_MATCH],

            self::MENU           => [self::SCEGLI_QUANDO, self::ATTESA_MATCH, self::GESTIONE_PRENOTAZIONI],
            self::SCEGLI_QUANDO  => [self::VERIFICA_SLOT, self::MENU, self::GESTIONE_PRENOTAZIONI],
            self::VERIFICA_SLOT  => [self::PROPONI_SLOT, self::MENU, self::GESTIONE_PRENOTAZIONI],
            self::PROPONI_SLOT   => [self::CONFERMA, self::SCEGLI_QUANDO, self::MENU, self::GESTIONE_PRENOTAZIONI],
            self::CONFERMA       => [self::PAGAMENTO, self::CONFERMATO, self::SCEGLI_QUANDO, self::MENU, self::GESTIONE_PRENOTAZIONI],
            self::PAGAMENTO      => [self::CONFERMATO, self::MENU, self::GESTIONE_PRENOTAZIONI],
            self::CONFERMATO     => [self::MENU, self::GESTIONE_PRENOTAZIONI],

            self::ATTESA_MATCH          => [self::SCEGLI_QUANDO, self::MENU, self::GESTIONE_PRENOTAZIONI],
            self::GESTIONE_PRENOTAZIONI => [self::AZIONE_PRENOTAZIONE, self::MENU],
            self::AZIONE_PRENOTAZIONE   => [self::SCEGLI_QUANDO, self::MENU],
        };
    }

    /**
     * Controlla se la transizione verso $target è valida.
     */
    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /**
     * Valida e restituisce il nuovo stato, oppure rimane invariato.
     */
    public function transitionTo(self $target): self
    {
        return $this->canTransitionTo($target) ? $target : $this;
    }

    /**
     * Indica se siamo in un flusso di onboarding.
     */
    public function isOnboarding(): bool
    {
        return in_array($this, [
            self::NEW,
            self::ONBOARD_NOME,
            self::ONBOARD_FIT,
            self::ONBOARD_CLASSIFICA,
            self::ONBOARD_LIVELLO,
            self::ONBOARD_ETA,
            self::ONBOARD_SLOT_PREF,
            self::ONBOARD_COMPLETO,
        ], true);
    }
}
