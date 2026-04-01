<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PricingRuleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'label'            => $this->label,
            'day_of_week'      => $this->day_of_week,
            'specific_date'    => $this->specific_date?->format('Y-m-d'),
            'start_time'       => substr($this->start_time ?? '', 0, 5),
            'end_time'         => substr($this->end_time ?? '', 0, 5),
            'duration_minutes' => $this->duration_minutes,
            'price'            => $this->price ? (float) $this->price : null,
            'price_per_hour'   => $this->price_per_hour ? (float) $this->price_per_hour : null,
            'is_peak'          => $this->is_peak,
            'is_active'        => $this->is_active,
            'priority'         => $this->priority,
        ];
    }
}
