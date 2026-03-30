<?php

namespace App\Services;

use Carbon\Carbon;
use Google\Client as GoogleClient;
use Google\Service\Calendar as GoogleCalendar;
use Google\Service\Calendar\Event as GoogleEvent;
use Google\Service\Calendar\EventDateTime;
use Google\Service\Calendar\FreeBusyRequest;
use Google\Service\Calendar\FreeBusyRequestItem;
use Illuminate\Support\Facades\Log;

/**
 * Servizio Google Calendar per Le Cercle Tennis Club.
 *
 * Responsabilità:
 * - Verificare disponibilità slot
 * - Creare eventi (prenotazioni)
 * - Proporre alternative quando uno slot è occupato
 */
class CalendarService
{
    private GoogleCalendar $service;
    private string $calendarId;
    private string $timezone = 'Europe/Rome';

    public function __construct()
    {
        $credentialsPath = config('services.google_calendar.credentials');
        $this->calendarId = config('services.google_calendar.calendar_id');

        if (empty($credentialsPath) || !file_exists($credentialsPath)) {
            throw new \RuntimeException(
                "Google Calendar credentials non trovate: {$credentialsPath}. "
                . "Verifica GOOGLE_CALENDAR_CREDENTIALS nel .env."
            );
        }

        if (empty($this->calendarId)) {
            throw new \RuntimeException(
                'Google Calendar ID non configurato. Verifica GOOGLE_CALENDAR_ID nel .env.'
            );
        }

        $client = new GoogleClient();
        $client->setAuthConfig($credentialsPath);
        $client->addScope(GoogleCalendar::CALENDAR);

        $this->service = new GoogleCalendar($client);
    }

    /* ═══════════════════════════════════════════════════════════════
     *  VERIFICA DISPONIBILITÀ
     * ═══════════════════════════════════════════════════════════════ */

    /**
     * Controlla se uno slot richiesto dall'utente è libero.
     * Se non è libero, propone alternative nello stesso giorno.
     *
     * @param  string $userRequest  Testo con data/ora (es. "2026-03-29 17:00")
     * @return array{available: bool, alternatives: array}
     */
    public function checkUserRequest(string $userRequest): array
    {
        try {
            $parsed = $this->parseSlotRequest($userRequest);

            if ($parsed === null) {
                return ['available' => false, 'alternatives' => [], 'error' => 'parse_failed'];
            }

            $start = $parsed['start'];
            $end   = $parsed['end'];

            $isAvailable = $this->isSlotFree($start, $end);

            if ($isAvailable) {
                return [
                    'available' => true,
                    'alternatives' => [],
                ];
            }

            // Slot occupato: cerca alternative nello stesso giorno
            $alternatives = $this->findAlternatives($start->copy()->startOfDay(), $start->copy()->endOfDay());

            return [
                'available'    => false,
                'alternatives' => $alternatives,
            ];
        } catch (\Throwable $e) {
            Log::error('CalendarService: checkUserRequest failed', [
                'request' => $userRequest,
                'error'   => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Verifica se uno specifico slot è libero controllando gli eventi esistenti.
     */
    private function isSlotFree(Carbon $start, Carbon $end): bool
    {
        $events = $this->service->events->listEvents($this->calendarId, [
            'timeMin'      => $start->toRfc3339String(),
            'timeMax'      => $end->toRfc3339String(),
            'singleEvents' => true,
            'maxResults'   => 1,
        ]);

        return count($events->getItems()) === 0;
    }

    /**
     * Trova slot liberi (da 1 ora) in un range giornaliero.
     * Orari operativi: 08:00 – 22:00.
     */
    private function findAlternatives(Carbon $dayStart, Carbon $dayEnd): array
    {
        $operatingStart = $dayStart->copy()->setTime(8, 0);
        $operatingEnd   = $dayStart->copy()->setTime(22, 0);

        // Recupera tutti gli eventi del giorno
        $events = $this->service->events->listEvents($this->calendarId, [
            'timeMin'      => $operatingStart->toRfc3339String(),
            'timeMax'      => $operatingEnd->toRfc3339String(),
            'singleEvents' => true,
            'orderBy'      => 'startTime',
            'maxResults'   => 50,
        ]);

        // Costruisci la lista di periodi occupati
        $busySlots = [];
        foreach ($events->getItems() as $event) {
            $eventStart = Carbon::parse($event->getStart()->getDateTime(), $this->timezone);
            $eventEnd   = Carbon::parse($event->getEnd()->getDateTime(), $this->timezone);
            $busySlots[] = ['start' => $eventStart, 'end' => $eventEnd];
        }

        // Trova slot liberi di 1 ora
        $alternatives = [];
        $cursor = $operatingStart->copy();

        while ($cursor->copy()->addHour()->lte($operatingEnd)) {
            $slotStart = $cursor->copy();
            $slotEnd   = $cursor->copy()->addHour();

            // Salta slot nel passato
            if ($slotStart->lt(now())) {
                $cursor->addHour();
                continue;
            }

            $isFree = true;
            foreach ($busySlots as $busy) {
                // Overlap check
                if ($slotStart->lt($busy['end']) && $slotEnd->gt($busy['start'])) {
                    $isFree = false;
                    break;
                }
            }

            if ($isFree) {
                $alternatives[] = [
                    'date'  => $slotStart->format('Y-m-d'),
                    'time'  => $slotStart->format('H:i'),
                    'label' => $slotStart->format('H:i') . ' - ' . $slotEnd->format('H:i'),
                    'price' => $this->estimatePrice($slotStart),
                ];
            }

            $cursor->addHour();
        }

        return array_slice($alternatives, 0, 5); // Max 5 alternative
    }

    /* ═══════════════════════════════════════════════════════════════
     *  CREAZIONE EVENTI
     * ═══════════════════════════════════════════════════════════════ */

    /**
     * Crea un evento sul Google Calendar.
     */
    public function createEvent(
        string $summary,
        string $description,
        Carbon $startTime,
        Carbon $endTime,
    ): GoogleEvent {
        $event = new GoogleEvent([
            'summary'     => $summary,
            'description' => $description,
            'start'       => [
                'dateTime' => $startTime->toRfc3339String(),
                'timeZone' => $this->timezone,
            ],
            'end' => [
                'dateTime' => $endTime->toRfc3339String(),
                'timeZone' => $this->timezone,
            ],
        ]);

        $createdEvent = $this->service->events->insert($this->calendarId, $event);

        Log::info('CalendarService: event created', [
            'event_id' => $createdEvent->getId(),
            'summary'  => $summary,
            'start'    => $startTime->toIso8601String(),
            'end'      => $endTime->toIso8601String(),
        ]);

        return $createdEvent;
    }

    /**
     * Elimina un evento dal Google Calendar dato il suo ID.
     */
    public function deleteEvent(string $eventId): void
    {
        $this->service->events->delete($this->calendarId, $eventId);

        Log::info('CalendarService: event deleted', ['event_id' => $eventId]);
    }

    /* ═══════════════════════════════════════════════════════════════
     *  HELPER
     * ═══════════════════════════════════════════════════════════════ */

    /**
     * Parsa la richiesta utente in un range start/end.
     * Accetta: "2026-03-29 17:00" oppure testo libero (fallback).
     */
    private function parseSlotRequest(string $request): ?array
    {
        // Formato esatto: "YYYY-MM-DD HH:MM"
        if (preg_match('/(\d{4}-\d{2}-\d{2})\s+(\d{1,2}:\d{2})/', $request, $m)) {
            $start = Carbon::parse("{$m[1]} {$m[2]}", $this->timezone);
            return [
                'start' => $start,
                'end'   => $start->copy()->addHour(),
            ];
        }

        // Fallback: prova a parsare come data Carbon
        try {
            $start = Carbon::parse($request, $this->timezone);
            return [
                'start' => $start,
                'end'   => $start->copy()->addHour(),
            ];
        } catch (\Throwable $e) {
            Log::warning('CalendarService: cannot parse slot request', [
                'request' => $request,
                'error'   => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Stima il prezzo per uno slot (placeholder — da collegare alle pricing_rules del DB).
     */
    private function estimatePrice(Carbon $slotStart): float
    {
        $hour = $slotStart->hour;

        // Fasce orarie base
        if ($hour >= 8 && $hour < 14) {
            return 20.00;  // Mattina
        }
        if ($hour >= 14 && $hour < 18) {
            return 25.00;  // Pomeriggio
        }

        return 30.00;  // Sera (peak)
    }
}
