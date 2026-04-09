<?php

namespace App\Filament\Widgets;

use App\Models\Booking;
use Filament\Widgets\ChartWidget;

class WeeklyBookingsChart extends ChartWidget
{
    protected static ?string $heading = 'Prenotazioni — ultimi 7 giorni';
    protected static ?int $sort = 2;
    protected int | string | array $columnSpan = 1;
    protected static ?string $maxHeight = '280px';

    protected function getData(): array
    {
        $start = today()->subDays(6)->format('Y-m-d');
        $end   = today()->format('Y-m-d');

        $rows = Booking::whereBetween('booking_date', [$start, $end])
            ->whereIn('status', ['confirmed', 'pending_match', 'completed'])
            ->selectRaw('booking_date, status, count(*) as cnt')
            ->groupBy('booking_date', 'status')
            ->get();

        $grouped = [];
        foreach ($rows as $r) {
            $key = $r->booking_date instanceof \Carbon\Carbon
                ? $r->booking_date->format('Y-m-d')
                : (string) $r->booking_date;
            $grouped[$key][$r->status] = $r->cnt;
        }

        $dayLabels = ['Dom', 'Lun', 'Mar', 'Mer', 'Gio', 'Ven', 'Sab'];
        $labels    = [];
        $confirmed = [];
        $pending   = [];
        $completed = [];

        for ($i = 6; $i >= 0; $i--) {
            $d  = today()->subDays($i);
            $ds = $d->format('Y-m-d');
            $labels[]    = $dayLabels[$d->dayOfWeek] . ' ' . $d->day;
            $confirmed[] = $grouped[$ds]['confirmed'] ?? 0;
            $pending[]   = $grouped[$ds]['pending_match'] ?? 0;
            $completed[] = $grouped[$ds]['completed'] ?? 0;
        }

        return [
            'datasets' => [
                [
                    'label'           => 'Confermate',
                    'data'            => $confirmed,
                    'backgroundColor' => '#10b981',
                    'borderRadius'    => 4,
                ],
                [
                    'label'           => 'In attesa',
                    'data'            => $pending,
                    'backgroundColor' => '#f59e0b',
                    'borderRadius'    => 4,
                ],
                [
                    'label'           => 'Completate',
                    'data'            => $completed,
                    'backgroundColor' => '#0ea5e9',
                    'borderRadius'    => 4,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => [
                'legend' => ['display' => true, 'position' => 'bottom'],
            ],
            'scales' => [
                'x' => ['stacked' => true],
                'y' => ['stacked' => true, 'beginAtZero' => true, 'ticks' => ['stepSize' => 1]],
            ],
        ];
    }
}
