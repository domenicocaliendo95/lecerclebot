<?php

namespace App\Services;

use Google\Client;
use Google\Service\Calendar;
use Google\Service\Calendar\Event;
use Google\Service\Calendar\EventDateTime;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class CalendarService
{
    private Calendar $calendar;
    private string $calendarId;

    public function __construct()
    {
        $client = new Client();
        $client->setAuthConfig(env('GOOGLE_CALENDAR_CREDENTIALS'));
        $client->addScope(Calendar::CALENDAR);

        $this->calendar   = new Calendar($client);
        $this->calendarId = env('GOOGLE_CALENDAR_ID');
    }

    /**
     * Restituisce i prossimi N slot liberi a partire da adesso.
     * Cerca buchi di almeno $durationMinutes minuti nell'orario del circolo.
     */
    public function getFreeSlots(int $count = 5, int $durationMinutes = 60): array
    {
        $now   = Carbon::now('Europe/Rome');
        $until = $now->copy()->addDays(7);

        // Recupera gli eventi esistenti
        $events = $this->calendar->events->listEvents($this->calendarId, [
            'timeMin'      => $now->toRfc3339String(),
            'timeMax'      => $until->toRfc3339String(),
            'singleEvents' => true,
            'orderBy'      => 'startTime',
        ]);

        $busySlots = [];
        foreach ($events->getItems() as $event) {
            $start = $event->getStart()->getDateTime() ?? $event->getStart()->getDate();
            $end   = $event->getEnd()->getDateTime()   ?? $event->getEnd()->getDate();
            $busySlots[] = [
                'start' => Carbon::parse($start, 'Europe/Rome'),
                'end'   => Carbon::parse($end, 'Europe/Rome'),
            ];
        }

        // Genera slot candidati negli orari del circolo (08:00–22:00)
        $freeSlots = [];
        $cursor    = $now->copy()->addHour()->startOfHour();

        while (count($freeSlots) < $count && $cursor < $until) {
            $hour = $cursor->hour;

            // Solo negli orari del circolo
            if ($hour >= 8 && $hour < 21) {
                $slotEnd = $cursor->copy()->addMinutes($durationMinutes);
                $isFree  = true;

                foreach ($busySlots as $busy) {
                    // Verifica sovrapposizione
                    if ($cursor < $busy['end'] && $slotEnd > $busy['start']) {
                        $isFree = false;
                        break;
                    }
                }

                if ($isFree) {
                    $freeSlots[] = [
                        'start'    => $cursor->copy(),
                        'end'      => $slotEnd->copy(),
                        'label'    => $cursor->isoFormat('ddd D MMM · HH:mm'),
                        'price'    => $this->getPrice($cursor),
                        'datetime' => $cursor->toIso8601String(),
                    ];
                }
            }

            $cursor->addHour();
        }

        return $freeSlots;
    }

    /**
     * Verifica se un orario richiesto dall'utente è disponibile
     * e restituisce alternative se non lo è.
     */
    public function checkUserRequest(string $userInput): array
    {
        // Per ora usiamo Carbon per parsare la data dall'input
        // In futuro Gemini può estrarre la data strutturata
        try {
            $requested = Carbon::parse($userInput, 'Europe/Rome');
        } catch (\Exception $e) {
            // Se non riesce a parsare, prende il prossimo slot libero
            $requested = Carbon::now('Europe/Rome')->addDay()->setHour(18)->setMinute(0);
        }

        $requestedEnd = $requested->copy()->addHour();

        // Recupera eventi del giorno
        $startOfDay = $requested->copy()->startOfDay();
        $endOfDay   = $requested->copy()->endOfDay();

        $events = $this->calendar->events->listEvents($this->calendarId, [
            'timeMin'      => $startOfDay->toRfc3339String(),
            'timeMax'      => $endOfDay->toRfc3339String(),
            'singleEvents' => true,
            'orderBy'      => 'startTime',
        ]);

        $busySlots = [];
        foreach ($events->getItems() as $event) {
            $start = Carbon::parse($event->getStart()->getDateTime() ?? $event->getStart()->getDate(), 'Europe/Rome');
            $end   = Carbon::parse($event->getEnd()->getDateTime()   ?? $event->getEnd()->getDate(), 'Europe/Rome');
            $busySlots[] = ['start' => $start, 'end' => $end];
        }

        // Verifica se lo slot richiesto è libero
        $isFree = true;
        foreach ($busySlots as $busy) {
            if ($requested < $busy['end'] && $requestedEnd > $busy['start']) {
                $isFree = false;
                break;
            }
        }

        if ($isFree) {
            return [
                'available' => true,
                'slot' => [
                    'start'    => $requested,
                    'end'      => $requestedEnd,
                    'label'    => $requested->isoFormat('ddd D MMM · HH:mm'),
                    'price'    => $this->getPrice($requested),
                    'datetime' => $requested->toIso8601String(),
                ],
            ];
        }

        // Trova alternative nello stesso giorno
        $alternatives = [];
        $cursor = $startOfDay->copy()->setHour(8);

        while ($cursor <= $endOfDay->copy()->setHour(21) && count($alternatives) < 3) {
            $cursorEnd = $cursor->copy()->addHour();
            $free = true;

            foreach ($busySlots as $busy) {
                if ($cursor < $busy['end'] && $cursorEnd > $busy['start']) {
                    $free = false;
                    break;
                }
            }

            if ($free && $cursor->isAfter(Carbon::now('Europe/Rome'))) {
                $alternatives[] = [
                    'start'    => $cursor->copy(),
                    'end'      => $cursorEnd->copy(),
                    'label'    => $cursor->isoFormat('HH:mm'),
                    'price'    => $this->getPrice($cursor),
                    'datetime' => $cursor->toIso8601String(),
                ];
            }

            $cursor->addHour();
        }

        return [
            'available'    => false,
            'alternatives' => $alternatives,
        ];
    }

    /**
     * Crea un evento nel calendario per la prenotazione confermata.
     */
    public function createBookingEvent(string $title, Carbon $start, Carbon $end, string $description = ''): string
    {
        $event = new Event([
            'summary'     => $title,
            'description' => $description,
            'start'       => new EventDateTime([
                'dateTime' => $start->toRfc3339String(),
                'timeZone' => 'Europe/Rome',
            ]),
            'end' => new EventDateTime([
                'dateTime' => $end->toRfc3339String(),
                'timeZone' => 'Europe/Rome',
            ]),
            'colorId' => '2', // verde
        ]);

        try {
            $created = $this->calendar->events->insert($this->calendarId, $event);
            return $created->getId();
        } catch (\Exception $e) {
            Log::error('Google Calendar error', ['message' => $e->getMessage()]);
            return '';
        }
    }

    /**
     * Elimina un evento dal calendario (per cancellazioni).
     */
    public function deleteEvent(string $eventId): void
    {
        try {
            $this->calendar->events->delete($this->calendarId, $eventId);
        } catch (\Exception $e) {
            Log::error('Google Calendar delete error', ['message' => $e->getMessage()]);
        }
    }

    /**
     * Calcola il prezzo in base alla fascia oraria.
     */
    private function getPrice(Carbon $dt): float
    {
        $hour      = $dt->hour;
        $dayOfWeek = $dt->dayOfWeek; // 0=dom, 6=sab

        $isWeekend = in_array($dayOfWeek, [0, 6]);

        if ($isWeekend && $hour >= 9 && $hour < 13) return 16.00; // super peak
        if ($isWeekend) return 13.00;                               // weekend
        if ($hour >= 17 && $hour < 21) return 15.00;               // peak serale
        return 10.00;                                               // off-peak
    }
}
