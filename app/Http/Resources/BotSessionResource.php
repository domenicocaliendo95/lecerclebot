<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BotSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = $this->data ?? [];

        return [
            'id'         => $this->id,
            'phone'      => $this->phone,
            'state'      => $this->state,
            'persona'    => $data['persona'] ?? null,
            'profile'    => $data['profile'] ?? null,
            'history'    => $data['history'] ?? [],
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
