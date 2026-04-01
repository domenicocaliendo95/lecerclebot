<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\MatchInvitation;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function stats(): JsonResponse
    {
        $today     = Carbon::today();
        $yesterday = Carbon::yesterday();
        $weekAgo   = $today->copy()->subWeek();

        // Prenotazioni oggi
        $bookingsToday = Booking::whereDate('booking_date', $today)
            ->whereIn('status', ['confirmed', 'pending_match', 'completed'])
            ->count();

        $bookingsYesterday = Booking::whereDate('booking_date', $yesterday)
            ->whereIn('status', ['confirmed', 'pending_match', 'completed'])
            ->count();

        $bookingsTrend = $bookingsYesterday > 0
            ? round(($bookingsToday - $bookingsYesterday) / $bookingsYesterday * 100, 1)
            : 0;

        // Incasso oggi
        $revenueToday = Booking::whereDate('booking_date', $today)
            ->whereIn('status', ['confirmed', 'completed'])
            ->sum('price');

        $revenueYesterday = Booking::whereDate('booking_date', $yesterday)
            ->whereIn('status', ['confirmed', 'completed'])
            ->sum('price');

        $revenueTrend = $revenueYesterday > 0
            ? round(($revenueToday - $revenueYesterday) / $revenueYesterday * 100, 1)
            : 0;

        // Giocatori
        $totalPlayers   = User::where('is_admin', false)->count();
        $newPlayersWeek = User::where('is_admin', false)
            ->where('created_at', '>=', $weekAgo)
            ->count();

        // Match in attesa
        $pendingMatches = MatchInvitation::where('status', 'pending')->count();

        return response()->json([
            'bookings_today'       => $bookingsToday,
            'bookings_today_trend' => $bookingsTrend,
            'revenue_today'        => (float) $revenueToday,
            'revenue_today_trend'  => $revenueTrend,
            'total_players'        => $totalPlayers,
            'new_players_week'     => $newPlayersWeek,
            'pending_matches'      => $pendingMatches,
        ]);
    }

    public function weeklyChart(): JsonResponse
    {
        $end   = Carbon::today();
        $start = $end->copy()->subDays(6);

        $bookings = Booking::whereBetween('booking_date', [$start, $end])
            ->whereIn('status', ['confirmed', 'pending_match', 'completed'])
            ->selectRaw("DATE(booking_date) as date, status, COUNT(*) as total")
            ->groupBy('date', 'status')
            ->get();

        $period = CarbonPeriod::create($start, $end);
        $days   = [];

        foreach ($period as $day) {
            $dateStr   = $day->format('Y-m-d');
            $dayData   = $bookings->where('date', $dateStr);

            $days[] = [
                'date'      => $dateStr,
                'label'     => $day->locale('it')->isoFormat('ddd'),
                'confirmed' => (int) $dayData->where('status', 'confirmed')->sum('total'),
                'pending'   => (int) $dayData->where('status', 'pending_match')->sum('total'),
                'completed' => (int) $dayData->where('status', 'completed')->sum('total'),
            ];
        }

        return response()->json($days);
    }
}
