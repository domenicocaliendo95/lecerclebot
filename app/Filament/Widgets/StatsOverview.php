<?php

namespace App\Filament\Widgets;

use App\Models\Booking;
use App\Models\User;
use Carbon\Carbon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StatsOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $today     = today();
        $yesterday = today()->subDay();

        // ── Prenotazioni: oggi vs ieri ──
        $todayBookings = Booking::where('booking_date', $today)
            ->whereIn('status', ['confirmed', 'pending_match', 'completed'])
            ->count();

        $yesterdayBookings = Booking::where('booking_date', $yesterday)
            ->whereIn('status', ['confirmed', 'pending_match', 'completed'])
            ->count();

        // Sparkline ultimi 7 giorni
        $bookingSpark = $this->last7DaysCounts();

        // ── Incasso oggi vs ieri ──
        $todayRevenue = (float) Booking::where('booking_date', $today)
            ->whereIn('status', ['confirmed', 'completed'])
            ->sum('price');

        $yesterdayRevenue = (float) Booking::where('booking_date', $yesterday)
            ->whereIn('status', ['confirmed', 'completed'])
            ->sum('price');

        $revenueSpark = $this->last7DaysRevenue();

        // ── Giocatori ──
        $totalUsers     = User::count();
        $newThisWeek    = User::where('created_at', '>=', now()->startOfWeek(Carbon::MONDAY))->count();

        // ── Match in attesa ──
        $pendingMatches = Booking::where('status', 'pending_match')->count();

        return [
            Stat::make('Prenotazioni oggi', $todayBookings)
                ->description($this->trendLabel($todayBookings, $yesterdayBookings, 'vs ieri'))
                ->descriptionIcon($todayBookings >= $yesterdayBookings ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
                ->color($todayBookings >= $yesterdayBookings ? 'success' : 'danger')
                ->chart($bookingSpark),

            Stat::make('Incasso oggi', '€' . number_format($todayRevenue, 2, ',', '.'))
                ->description($this->trendLabel($todayRevenue, $yesterdayRevenue, 'vs ieri'))
                ->descriptionIcon($todayRevenue >= $yesterdayRevenue ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
                ->color($todayRevenue >= $yesterdayRevenue ? 'success' : 'danger')
                ->chart($revenueSpark),

            Stat::make('Giocatori registrati', $totalUsers)
                ->description($newThisWeek > 0 ? "+{$newThisWeek} questa settimana" : 'Nessun nuovo')
                ->descriptionIcon('heroicon-m-user-plus')
                ->color($newThisWeek > 0 ? 'info' : 'gray'),

            Stat::make('Match in attesa', $pendingMatches)
                ->description($pendingMatches > 0 ? 'In attesa di risposta' : 'Nessuno in coda')
                ->descriptionIcon($pendingMatches > 0 ? 'heroicon-m-clock' : 'heroicon-m-check-circle')
                ->color($pendingMatches > 0 ? 'warning' : 'success'),
        ];
    }

    private function trendLabel(float $current, float $previous, string $suffix): string
    {
        if ($previous == 0) {
            return $current > 0 ? "+{$this->formatNum($current)} {$suffix}" : "Invariato {$suffix}";
        }

        $diff    = $current - $previous;
        $sign    = $diff >= 0 ? '+' : '';
        $percent = round(($diff / $previous) * 100);

        return "{$sign}{$percent}% {$suffix}";
    }

    private function formatNum(float $n): string
    {
        return $n == (int) $n ? (string) (int) $n : number_format($n, 2, ',', '.');
    }

    private function last7DaysCounts(): array
    {
        $start = today()->subDays(6)->format('Y-m-d');
        $end   = today()->format('Y-m-d');

        $counts = Booking::whereBetween('booking_date', [$start, $end])
            ->whereIn('status', ['confirmed', 'pending_match', 'completed'])
            ->selectRaw('booking_date, count(*) as cnt')
            ->groupBy('booking_date')
            ->pluck('cnt', 'booking_date')
            ->toArray();

        $result = [];
        for ($i = 6; $i >= 0; $i--) {
            $d = today()->subDays($i)->format('Y-m-d');
            $result[] = $counts[$d] ?? 0;
        }

        return $result;
    }

    private function last7DaysRevenue(): array
    {
        $start = today()->subDays(6)->format('Y-m-d');
        $end   = today()->format('Y-m-d');

        $sums = Booking::whereBetween('booking_date', [$start, $end])
            ->whereIn('status', ['confirmed', 'completed'])
            ->selectRaw('booking_date, sum(price) as total')
            ->groupBy('booking_date')
            ->pluck('total', 'booking_date')
            ->toArray();

        $result = [];
        for ($i = 6; $i >= 0; $i--) {
            $d = today()->subDays($i)->format('Y-m-d');
            $result[] = (float) ($sums[$d] ?? 0);
        }

        return $result;
    }
}
