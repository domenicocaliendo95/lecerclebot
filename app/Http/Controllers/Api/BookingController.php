<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\BookingResource;
use App\Models\Booking;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BookingController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Booking::with(['player1', 'player2'])
            ->orderBy('booking_date', 'desc')
            ->orderBy('start_time', 'asc');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('date')) {
            $query->whereDate('booking_date', $request->input('date'));
        }

        if ($request->filled('from') && $request->filled('to')) {
            $query->whereBetween('booking_date', [$request->input('from'), $request->input('to')]);
        }

        if ($request->filled('player')) {
            $search = $request->input('player');
            $query->where(function ($q) use ($search) {
                $q->whereHas('player1', fn($q2) => $q2->where('name', 'like', "%{$search}%"))
                  ->orWhereHas('player2', fn($q2) => $q2->where('name', 'like', "%{$search}%"));
            });
        }

        return BookingResource::collection(
            $query->paginate($request->integer('per_page', 20))
        );
    }

    public function show(Booking $booking): BookingResource
    {
        return new BookingResource($booking->load(['player1', 'player2']));
    }

    /**
     * Prenotazioni di oggi per la dashboard.
     */
    public function today(): AnonymousResourceCollection
    {
        $bookings = Booking::with(['player1', 'player2'])
            ->whereDate('booking_date', today())
            ->whereIn('status', ['confirmed', 'pending_match', 'completed'])
            ->orderBy('start_time')
            ->get();

        return BookingResource::collection($bookings);
    }

    /**
     * Prenotazioni per il calendario (per data o range settimanale).
     */
    public function calendar(Request $request): JsonResponse
    {
        $request->validate([
            'from' => 'required|date',
            'to'   => 'required|date|after_or_equal:from',
        ]);

        $bookings = Booking::with(['player1', 'player2'])
            ->whereBetween('booking_date', [$request->input('from'), $request->input('to')])
            ->whereIn('status', ['confirmed', 'pending_match', 'completed'])
            ->orderBy('booking_date')
            ->orderBy('start_time')
            ->get();

        // Raggruppa per data
        $grouped = $bookings->groupBy(fn($b) => $b->booking_date->format('Y-m-d'))
            ->map(fn($dayBookings) => BookingResource::collection($dayBookings));

        return response()->json($grouped);
    }
}
