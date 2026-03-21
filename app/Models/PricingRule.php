<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PricingRule extends Model
{
    protected $fillable = [
        'day_of_week',
        'start_time',
        'end_time',
        'price_per_hour',
        'is_peak',
        'label',
    ];

    protected $casts = [
        'is_peak' => 'boolean',
    ];

    /**
     * Calcola il prezzo per uno slot dato giorno e orario
     */
    public static function getPriceForSlot(\Carbon\Carbon $datetime): float
    {
        $rule = self::where(function ($q) use ($datetime) {
            $q->whereNull('day_of_week')
                ->orWhere('day_of_week', $datetime->dayOfWeek);
        })
            ->where('start_time', '<=', $datetime->format('H:i:s'))
            ->where('end_time', '>', $datetime->format('H:i:s'))
            ->orderByDesc('price_per_hour')
            ->first();

        return $rule ? (float) $rule->price_per_hour : 10.00;
    }
}
