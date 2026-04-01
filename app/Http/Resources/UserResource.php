<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'name'             => $this->name,
            'phone'            => $this->phone,
            'is_fit'           => $this->is_fit,
            'fit_rating'       => $this->fit_rating,
            'self_level'       => $this->self_level,
            'age'              => $this->age,
            'elo_rating'       => $this->elo_rating,
            'matches_played'   => $this->matches_played,
            'matches_won'      => $this->matches_won,
            'is_elo_established' => $this->is_elo_established,
            'preferred_slots'  => $this->preferred_slots,
            'created_at'       => $this->created_at?->toIso8601String(),
        ];
    }
}
