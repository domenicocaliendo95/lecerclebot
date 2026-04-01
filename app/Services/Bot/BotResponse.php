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
    private ?array $profileToSave      = null;

    private function __construct(
        public readonly string   $message,
        public readonly BotState $nextState,
        public readonly array    $buttons,
    ) {}

    /* ───────── Factory ───────── */

    public static function make(string $message, BotState $nextState, array $buttons = []): self
    {
        return new self($message, $nextState, $buttons);
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

    public function profileToSave(): ?array
    {
        return $this->profileToSave;
    }

    public function hasButtons(): bool
    {
        return !empty($this->buttons) && count($this->buttons) <= 3;
    }
}
