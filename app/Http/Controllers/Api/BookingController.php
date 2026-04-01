<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\BookingResource;
use App\Models\Booking;
use App\Models\PricingRule;
use App\Models\User;
use Carbon\Carbon;
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

    public function store(Request $request): BookingResource
    {
        $validated = $request->validate([
            'player1_id'   => 'required|exists:users,id',
            'player2_id'   => 'nullable|exists:users,id',
            'booking_date'  => 'required|date',
            'start_time'    => 'required|date_format:H:i',
            'end_time'      => 'required|date_format:H:i|after:start_time',
            'status'        => 'in:confirmed,pending_match,completed,cancelled',
        ]);

        $startDt = Carbon::parse($validated['booking_date'] . ' ' . $validated['start_time'], 'Europe/Rome');
        $startMin = Carbon::parse($validated['start_time']);
        $endMin = Carbon::parse($validated['end_time']);
        $durationMinutes = $startMin->diffInMinutes($endMin);

        $price = PricingRule::getPriceForSlot($startDt, (int) $durationMinutes);

        $booking = Booking::create([
            ...$validated,
            'price'   => $price,
            'is_peak' => $startDt->hour >= 18,
            'status'  => $validated['status'] ?? 'confirmed',
        ]);

        return new BookingResource($booking->load(['player1', 'player2']));
    }

    public function update(Request $request, Booking $booking): BookingResource
    {
        $validated = $request->validate([
            'player1_id'   => 'sometimes|exists:users,id',
            'player2_id'   => 'nullable|exists:users,id',
            'booking_date'  => 'sometimes|date',
            'start_time'    => 'sometimes|date_format:H:i',
            'end_time'      => 'sometimes|date_format:H:i',
            'status'        => 'sometimes|in:confirmed,pending_match,completed,cancelled',
            'payment_status_p1' => 'sometimes|in:pending,paid',
            'payment_status_p2' => 'sometimes|in:pending,paid',
        ]);

        $booking->update($validated);

        // Ricalcola prezzo se data/ora cambiate
        if (isset($validated['booking_date']) || isset($validated['start_time']) || isset($validated['end_time'])) {
            $date = $booking->booking_date->format('Y-m-d');
            $startDt = Carbon::parse($date . ' ' . $booking->start_time, 'Europe/Rome');
            $startMin = Carbon::parse($booking->start_time);
            $endMin = Carbon::parse($booking->end_time);
            $durationMinutes = $startMin->diffInMinutes($endMin);

            $booking->update([
                'price'   => PricingRule::getPriceForSlot($startDt, (int) $durationMinutes),
                'is_peak' => $startDt->hour >= 18,
            ]);
        }

        return new BookingResource($booking->fresh()->load(['player1', 'player2']));
    }

    public function destroy(Booking $booking): JsonResponse
    {
        $booking->update(['status' => 'cancelled']);
        return response()->json(['message' => 'Prenotazione annullata.']);
    }

    public function today(): AnonymousResourceCollection
    {
        $bookings = Booking::with(['player1', 'player2'])
            ->whereDate('booking_date', today())
            ->whereIn('status', ['confirmed', 'pending_match', 'completed'])
            ->orderBy('start_time')
            ->get();

        return BookingResource::collection($bookings);
    }

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

        $grouped = $bookings->groupBy(fn($b) => $b->booking_date->format('Y-m-d'))
            ->map(fn($dayBookings) => BookingResource::collection($dayBookings));

        return response()->json($grouped);
    }
}
