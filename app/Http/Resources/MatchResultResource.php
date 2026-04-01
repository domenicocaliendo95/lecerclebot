<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MatchResultResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->id,
            'booking_id'        => $this->booking_id,
            'winner_id'         => $this->winner_id,
            'score'             => $this->score,
            'player1_elo_before' => $this->player1_elo_before,
            'player1_elo_after'  => $this->player1_elo_after,
            'player2_elo_before' => $this->player2_elo_before,
            'player2_elo_after'  => $this->player2_elo_after,
            'player1_confirmed'  => $this->player1_confirmed,
            'player2_confirmed'  => $this->player2_confirmed,
            'confirmed_at'       => $this->confirmed_at?->toIso8601String(),
            'created_at'         => $this->created_at?->toIso8601String(),
            'booking'            => new BookingResource($this->whenLoaded('booking')),
            'winner'             => new UserResource($this->whenLoaded('winner')),
        ];
    }
}
