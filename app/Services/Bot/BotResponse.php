<?php

namespace App\Services\Bot;

/**
 * DTO immutabile che rappresenta la risposta del bot.
 *
 * Contiene il testo, il nuovo stato, i pulsanti opzionali,
 * e flag per side-effect che l'orchestrator deve eseguire.
 */
class BotResponse
{
    private bool  $calendarCheck       = false;
    private bool  $paymentRequired     = false;
    private bool  $bookingToCreate     = false;
    private bool  $bookingToCancel     = false;
    private bool  $matchmakingToSearch = false;
    private bool  $matchAccepted       = false;
    private bool  $matchRefused        = false;
    private bool  $matchResultToSave   = false;
    private bool  $feedbackToSave      = false;
    private bool  $opponentLinkConfirmed = false;
    private bool  $opponentLinkRejected  = false;
    private ?array $profileToSave      = null;

    private function __construct(
        public readonly string          $message,
        public readonly BotState|string $nextState,
        public readonly array           $buttons,
    ) {}

    /* ───────── Factory ───────── */

    /**
     * Crea una risposta. `$nextState` può essere un BotState enum (built-in)
     * o una stringa (stato custom creato dal pannello).
     */
    public static function make(string $message, BotState|string $nextState, array $buttons = []): self
    {
        return new self($message, $nextState, $buttons);
    }

    /**
     * Restituisce il valore stringa dello stato successivo,
     * indipendentemente che sia un enum case o una stringa custom.
     */
    public function nextStateValue(): string
    {
        return $this->nextState instanceof BotState
            ? $this->nextState->value
            : $this->nextState;
    }

    /* ───────── Builder fluente per side-effect ───────── */

    public function withCalendarCheck(bool $flag): self
    {
        $this->calendarCheck = $flag;
        return $this;
    }

    public function withPaymentRequired(bool $flag): self
    {
        $this->paymentRequired = $flag;
        return $this;
    }

    public function withBookingToCreate(bool $flag): self
    {
        $this->bookingToCreate = $flag;
        return $this;
    }

    public function withBookingToCancel(bool $flag): self
    {
        $this->bookingToCancel = $flag;
        return $this;
    }

    public function withMatchmakingSearch(bool $flag): self
    {
        $this->matchmakingToSearch = $flag;
        return $this;
    }

    public function withMatchAccepted(bool $flag): self
    {
        $this->matchAccepted = $flag;
        return $this;
    }

    public function withMatchRefused(bool $flag): self
    {
        $this->matchRefused = $flag;
        return $this;
    }

    public function withMatchResultToSave(bool $flag): self
    {
        $this->matchResultToSave = $flag;
        return $this;
    }

    public function withFeedbackToSave(bool $flag): self
    {
        $this->feedbackToSave = $flag;
        return $this;
    }

    public function withOpponentLinkConfirmed(bool $flag): self
    {
        $this->opponentLinkConfirmed = $flag;
        return $this;
    }

    public function withOpponentLinkRejected(bool $flag): self
    {
        $this->opponentLinkRejected = $flag;
        return $this;
    }

    public function withProfileToSave(?array $profile): self
    {
        $this->profileToSave = $profile;
        return $this;
    }

    /* ───────── Getters ───────── */

    public function needsCalendarCheck(): bool
    {
        return $this->calendarCheck;
    }

    public function needsPayment(): bool
    {
        return $this->paymentRequired;
    }

    public function needsBookingCreation(): bool
    {
        return $this->bookingToCreate;
    }

    public function needsBookingCancellation(): bool
    {
        return $this->bookingToCancel;
    }

    public function needsMatchmakingSearch(): bool
    {
        return $this->matchmakingToSearch;
    }

    public function needsMatchAccepted(): bool
    {
        return $this->matchAccepted;
    }

    public function needsMatchRefused(): bool
    {
        return $this->matchRefused;
    }

    public function needsMatchResultSave(): bool
    {
        return $this->matchResultToSave;
    }

    public function needsFeedbackSave(): bool
    {
        return $this->feedbackToSave;
    }

    public function needsOpponentLinkConfirm(): bool
    {
        return $this->opponentLinkConfirmed;
    }

    public function needsOpponentLinkReject(): bool
    {
        return $this->opponentLinkRejected;
    }

    public function profileToSave(): ?array
    {
        return $this->profileToSave;
    }

    public function hasButtons(): bool
    {
        return !empty($this->buttons) && count($this->buttons) <= 3;
    }
}
