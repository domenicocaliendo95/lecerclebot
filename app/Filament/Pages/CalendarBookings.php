<?php

namespace App\Filament\Pages;

use App\Filament\Resources\BookingResource;
use App\Models\Booking;
use App\Models\PricingRule;
use App\Services\CalendarService;
use Carbon\Carbon;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;

class CalendarBookings extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-calendar';
    protected static ?string $navigationLabel = 'Calendario';
    protected static ?string $title = 'Calendario Prenotazioni';
    protected static ?int $navigationSort = 2;
    protected static string $view = 'filament.pages.calendar-bookings';

    #[Url]
    public string $selectedDate = '';

    public string $viewMode = 'day'; // day | week

    public ?array $selectedBooking = null;

    // ── Filtri ──
    public string $filterPlayer = '';
    public array $filterStatuses = ['confirmed', 'pending_match', 'completed'];

    public function mount(): void
    {
        if (! $this->selectedDate) {
            $this->selectedDate = today()->format('Y-m-d');
        }
    }

    public static function getNavigationBadge(): ?string
    {
        $count = Booking::where('booking_date', today())
            ->whereIn('status', ['confirmed', 'pending_match'])
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    // ── Navigazione ──

    public function previousPeriod(): void
    {
        $date = Carbon::parse($this->selectedDate);
        $this->selectedDate = $this->viewMode === 'week'
            ? $date->subWeek()->format('Y-m-d')
            : $date->subDay()->format('Y-m-d');
        $this->selectedBooking = null;
    }

    public function nextPeriod(): void
    {
        $date = Carbon::parse($this->selectedDate);
        $this->selectedDate = $this->viewMode === 'week'
            ? $date->addWeek()->format('Y-m-d')
            : $date->addDay()->format('Y-m-d');
        $this->selectedBooking = null;
    }

    public function goToToday(): void
    {
        $this->selectedDate = today()->format('Y-m-d');
        $this->selectedBooking = null;
    }

    public function setDate(string $date): void
    {
        $this->selectedDate = $date;
        $this->selectedBooking = null;
    }

    public function switchToDay(string $date): void
    {
        $this->selectedDate = $date;
        $this->viewMode = 'day';
        $this->selectedBooking = null;
    }

    public function setViewMode(string $mode): void
    {
        $this->viewMode = $mode;
        $this->selectedBooking = null;
    }

    // ── Filtri ──

    public function toggleStatus(string $status): void
    {
        if (in_array($status, $this->filterStatuses)) {
            $this->filterStatuses = array_values(array_diff($this->filterStatuses, [$status]));
        } else {
            $this->filterStatuses[] = $status;
        }
        $this->selectedBooking = null;
    }

    public function updatedFilterPlayer(): void
    {
        $this->selectedBooking = null;
    }

    // ── Click-to-create ──

    public function createAtSlot(string $date, string $time): void
    {
        $url = BookingResource::getUrl('create') . '?' . http_build_query([
            'date' => $date,
            'time' => $time,
        ]);

        $this->redirect($url);
    }

    // ── Drag & Drop ──

    public function moveBooking(int $id, string $newDate, string $newTime): void
    {
        $booking = Booking::with(['player1', 'player2'])->find($id);

        if (! $booking || $booking->status === 'completed') {
            Notification::make()->title('Impossibile spostare')->danger()->send();
            return;
        }

        $oldStart    = Carbon::parse($booking->start_time);
        $oldEnd      = Carbon::parse($booking->end_time);
        $durationMin = $oldStart->diffInMinutes($oldEnd);

        $newStart = Carbon::createFromFormat('H:i', $newTime);
        $newEnd   = $newStart->copy()->addMinutes($durationMin);

        if ($newStart->hour < 8 || $newEnd->hour > 22 || ($newEnd->hour === 22 && $newEnd->minute > 0)) {
            Notification::make()
                ->title('Orario non valido')
                ->body('La prenotazione deve rimanere tra le 08:00 e le 22:00.')
                ->danger()
                ->send();
            return;
        }

        // Aggiorna prezzo in base al nuovo slot
        $newPrice = PricingRule::getPriceForSlot(
            Carbon::parse($newDate . ' ' . $newStart->format('H:i:s'), 'Europe/Rome'),
            $durationMin
        );

        $booking->update([
            'booking_date' => $newDate,
            'start_time'   => $newStart->format('H:i:s'),
            'end_time'     => $newEnd->format('H:i:s'),
            'price'        => $newPrice,
            'is_peak'      => $newStart->hour >= 18,
        ]);

        // Aggiorna Google Calendar se sincronizzato
        if ($booking->gcal_event_id) {
            try {
                $cal = app(CalendarService::class);
                $cal->deleteEvent($booking->gcal_event_id);

                $startDt = Carbon::parse("{$newDate} {$newStart->format('H:i:s')}", 'Europe/Rome');
                $endDt   = Carbon::parse("{$newDate} {$newEnd->format('H:i:s')}", 'Europe/Rome');

                $summary = $booking->player2_id
                    ? "Partita singolo - {$booking->player1->name} vs {$booking->player2->name}"
                    : "Prenotazione - {$booking->player1->name}";

                $event = $cal->createEvent($summary, '', $startDt, $endDt);
                $booking->update(['gcal_event_id' => $event->getId()]);
            } catch (\Throwable $e) {
                Log::error('Calendar update failed on drag-move', ['error' => $e->getMessage()]);
            }
        }

        $this->selectedBooking = null;

        Notification::make()
            ->title('Prenotazione spostata')
            ->body("{$newStart->format('H:i')}–{$newEnd->format('H:i')} del " . Carbon::parse($newDate)->format('d/m'))
            ->success()
            ->send();
    }

    // ── Dettaglio prenotazione ──

    public function selectBooking(int $id): void
    {
        $booking = Booking::with(['player1', 'player2'])->find($id);
        if (! $booking) return;

        $days   = ['Domenica', 'Lunedì', 'Martedì', 'Mercoledì', 'Giovedì', 'Venerdì', 'Sabato'];
        $months = ['', 'Gennaio', 'Febbraio', 'Marzo', 'Aprile', 'Maggio', 'Giugno',
                   'Luglio', 'Agosto', 'Settembre', 'Ottobre', 'Novembre', 'Dicembre'];
        $d = $booking->booking_date;

        $this->selectedBooking = [
            'id'            => $booking->id,
            'player1'       => $booking->player1?->name ?? '—',
            'player1_phone' => $booking->player1?->phone,
            'player2'       => $booking->player2?->name,
            'player2_phone' => $booking->player2?->phone,
            'date'          => $days[$d->dayOfWeek] . ' ' . $d->day . ' ' . $months[$d->month] . ' ' . $d->year,
            'start'         => Carbon::parse($booking->start_time)->format('H:i'),
            'end'           => Carbon::parse($booking->end_time)->format('H:i'),
            'status'        => $booking->status,
            'price'         => number_format((float) $booking->price, 2, ',', '.'),
            'is_peak'       => $booking->is_peak,
            'has_gcal'      => ! empty($booking->gcal_event_id),
            'payment_p1'    => $booking->payment_status_p1,
            'payment_p2'    => $booking->payment_status_p2,
            'edit_url'      => BookingResource::getUrl('edit', ['record' => $booking->id]),
        ];
    }

    public function closeDetail(): void
    {
        $this->selectedBooking = null;
    }

    // ── Computed: prenotazioni giorno ──

    #[Computed]
    public function bookings(): Collection
    {
        return $this->queryBookings($this->selectedDate);
    }

    // ── Computed: prenotazioni settimana (raggruppate per data) ──

    #[Computed]
    public function weekBookings(): array
    {
        $start = Carbon::parse($this->selectedDate)->startOfWeek(Carbon::MONDAY);
        $end   = $start->copy()->addDays(6);

        $all = Booking::with(['player1', 'player2'])
            ->whereBetween('booking_date', [$start->format('Y-m-d'), $end->format('Y-m-d')])
            ->whereIn('status', $this->filterStatuses ?: ['__none__'])
            ->when($this->filterPlayer, function ($q) {
                $s = $this->filterPlayer;
                $q->where(fn ($q2) => $q2
                    ->whereHas('player1', fn ($q3) => $q3->where('name', 'like', "%{$s}%"))
                    ->orWhereHas('player2', fn ($q3) => $q3->where('name', 'like', "%{$s}%"))
                );
            })
            ->orderBy('start_time')
            ->get();

        $grouped = [];
        for ($i = 0; $i < 7; $i++) {
            $grouped[$start->copy()->addDays($i)->format('Y-m-d')] = collect();
        }

        foreach ($all as $b) {
            $ds          = $b->booking_date->format('Y-m-d');
            $startT      = Carbon::parse($b->start_time);
            $endT        = Carbon::parse($b->end_time);
            $startMin    = $startT->hour * 60 + $startT->minute;
            $durationMin = $startT->diffInMinutes($endT);

            $grouped[$ds][] = [
                'id'      => $b->id,
                'player1' => $b->player1?->name ?? '—',
                'player2' => $b->player2?->name,
                'start'   => $startT->format('H:i'),
                'end'     => $endT->format('H:i'),
                'top'     => ($startMin - 480) / 60 * 80,
                'height'  => max($durationMin / 60 * 80, 36),
                'status'  => $b->status,
                'price'   => (float) $b->price,
                'is_peak' => $b->is_peak,
            ];
        }

        return $grouped;
    }

    // ── Computed: settimana ──

    #[Computed]
    public function weekDays(): array
    {
        $start  = Carbon::parse($this->selectedDate)->startOfWeek(Carbon::MONDAY);
        $end    = $start->copy()->addDays(6);

        $counts = Booking::whereBetween('booking_date', [$start->format('Y-m-d'), $end->format('Y-m-d')])
            ->whereIn('status', ['confirmed', 'pending_match', 'completed'])
            ->selectRaw('booking_date, count(*) as cnt')
            ->groupBy('booking_date')
            ->pluck('cnt', 'booking_date')
            ->toArray();

        $labels = ['Lun', 'Mar', 'Mer', 'Gio', 'Ven', 'Sab', 'Dom'];
        $days   = [];

        for ($i = 0; $i < 7; $i++) {
            $d  = $start->copy()->addDays($i);
            $ds = $d->format('Y-m-d');
            $days[] = [
                'date'     => $ds,
                'day'      => $d->day,
                'label'    => $labels[$i],
                'selected' => $ds === $this->selectedDate,
                'today'    => $d->isToday(),
                'count'    => $counts[$ds] ?? 0,
            ];
        }

        return $days;
    }

    #[Computed]
    public function formattedDate(): string
    {
        $date   = Carbon::parse($this->selectedDate);
        $days   = ['Domenica', 'Lunedì', 'Martedì', 'Mercoledì', 'Giovedì', 'Venerdì', 'Sabato'];
        $months = ['', 'Gennaio', 'Febbraio', 'Marzo', 'Aprile', 'Maggio', 'Giugno',
                   'Luglio', 'Agosto', 'Settembre', 'Ottobre', 'Novembre', 'Dicembre'];

        if ($this->viewMode === 'week') {
            $s = $date->copy()->startOfWeek(Carbon::MONDAY);
            $e = $s->copy()->addDays(6);
            if ($s->month === $e->month) {
                return $s->day . ' – ' . $e->day . ' ' . $months[$s->month] . ' ' . $s->year;
            }
            return $s->day . ' ' . $months[$s->month] . ' – ' . $e->day . ' ' . $months[$e->month] . ' ' . $e->year;
        }

        return $days[$date->dayOfWeek] . ' ' . $date->day . ' ' . $months[$date->month] . ' ' . $date->year;
    }

    #[Computed]
    public function stats(): array
    {
        if ($this->viewMode === 'week') {
            $allBookings = collect($this->weekBookings)->flatten(1);
        } else {
            $allBookings = $this->bookings;
        }

        return [
            'total'     => $allBookings->count(),
            'confirmed' => $allBookings->where('status', 'confirmed')->count(),
            'pending'   => $allBookings->where('status', 'pending_match')->count(),
            'revenue'   => number_format((float) $allBookings->sum('price'), 2, ',', '.'),
        ];
    }

    #[Computed]
    public function currentTimePosition(): ?float
    {
        if ($this->selectedDate !== today()->format('Y-m-d') && $this->viewMode === 'day') return null;

        $now     = now();
        $minutes = $now->hour * 60 + $now->minute;
        if ($minutes < 480 || $minutes > 1320) return null;

        return ($minutes - 480) / 60 * 80;
    }

    #[Computed]
    public function todayColumnIndex(): ?int
    {
        if ($this->viewMode !== 'week') return null;

        foreach ($this->weekDays as $i => $wd) {
            if ($wd['today']) return $i;
        }

        return null;
    }

    // ── Private ──

    private function queryBookings(string $date): Collection
    {
        return Booking::with(['player1', 'player2'])
            ->where('booking_date', $date)
            ->whereIn('status', $this->filterStatuses ?: ['__none__'])
            ->when($this->filterPlayer, function ($q) {
                $s = $this->filterPlayer;
                $q->where(fn ($q2) => $q2
                    ->whereHas('player1', fn ($q3) => $q3->where('name', 'like', "%{$s}%"))
                    ->orWhereHas('player2', fn ($q3) => $q3->where('name', 'like', "%{$s}%"))
                );
            })
            ->orderBy('start_time')
            ->get()
            ->map(function (Booking $b) {
                $start       = Carbon::parse($b->start_time);
                $end         = Carbon::parse($b->end_time);
                $startMin    = $start->hour * 60 + $start->minute;
                $durationMin = $start->diffInMinutes($end);

                return [
                    'id'      => $b->id,
                    'player1' => $b->player1?->name ?? '—',
                    'player2' => $b->player2?->name,
                    'start'   => $start->format('H:i'),
                    'end'     => $end->format('H:i'),
                    'top'     => ($startMin - 480) / 60 * 80,
                    'height'  => max($durationMin / 60 * 80, 36),
                    'status'  => $b->status,
                    'price'   => (float) $b->price,
                    'is_peak' => $b->is_peak,
                ];
            });
    }
}
