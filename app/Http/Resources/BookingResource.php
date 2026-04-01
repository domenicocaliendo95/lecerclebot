<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BookingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'player1_id'     => $this->player1_id,
            'player2_id'     => $this->player2_id,
            'booking_date'   => $this->booking_date?->format('Y-m-d'),
            'start_time'     => substr($this->start_time, 0, 5),
            'end_time'       => substr($this->end_time, 0, 5),
            'price'          => (float) $this->price,
            'is_peak'        => $this->is_peak,
            'status'         => $this->status,
            'gcal_event_id'  => $this->gcal_event_id,
            'payment_status_p1' => $this->payment_status_p1,
            'payment_status_p2' => $this->payment_status_p2,
            'created_at'     => $this->created_at?->toIso8601String(),
            'player1'        => new UserResource($this->whenLoaded('player1')),
            'player2'        => new UserResource($this->whenLoaded('player2')),
        ];
    }
}
