<?php
namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class PricingRule extends Model
{
    protected $fillable = [
        'label', 'day_of_week', 'specific_date',
        'start_time', 'end_time',
        'duration_minutes', 'price', 'price_per_hour',
        'is_peak', 'is_active', 'priority',
    ];

    protected $casts = [
        'is_peak'    => 'boolean',
        'is_active'  => 'boolean',
        'specific_date' => 'date',
    ];

    /**
     * Restituisce il prezzo per uno slot dato il datetime di inizio e la durata.
     *
     * Priorità: specific_date > day_of_week specifico > regola generica (null day_of_week)
     * A parità, priority DESC.
     *
     * @param  Carbon $startTime       Inizio slot
     * @param  int    $durationMinutes Durata in minuti (60, 90, 120...)
     * @return float  Prezzo per lo slot
     */
    public static function getPriceForSlot(Carbon $startTime, int $durationMinutes = 60): float
    {
        $timeStr = $startTime->format('H:i:s');
        $dateStr = $startTime->format('Y-m-d');
        $dow     = $startTime->dayOfWeek;

        $rule = self::where('is_active', true)
            ->where('start_time', '<=', $timeStr)
            ->where('end_time', '>', $timeStr)
            ->where(function ($q) use ($durationMinutes) {
                $q->whereNull('duration_minutes')
                  ->orWhere('duration_minutes', $durationMinutes);
            })
            ->where(function ($q) use ($dateStr, $dow) {
                $q->where('specific_date', $dateStr)           // override data specifica
                  ->orWhere(function ($q2) use ($dow) {
                      $q2->whereNull('specific_date')
                         ->where('day_of_week', $dow);         // giorno settimana
                  })
                  ->orWhere(function ($q2) {
                      $q2->whereNull('specific_date')
                         ->whereNull('day_of_week');            // regola generica
                  });
            })
            ->orderByRaw("CASE WHEN specific_date IS NOT NULL THEN 2
                               WHEN day_of_week IS NOT NULL THEN 1
                               ELSE 0 END DESC")
            ->orderBy('priority', 'desc')
            ->orderByRaw("CASE WHEN duration_minutes IS NOT NULL THEN 1 ELSE 0 END DESC")
            ->first();

        if (!$rule) {
            return 20.00; // fallback
        }

        // Usa price (flat per slot) se presente, altrimenti price_per_hour
        return (float) ($rule->price ?? $rule->price_per_hour);
    }

    /**
     * Restituisce le durate distinte configurate nelle regole attive, ordinate.
     * Se nessuna regola ha duration_minutes specifico, ritorna il default [60].
     *
     * @return int[]  es. [60, 90, 120]
     */
    public static function availableDurations(): array
    {
        $durations = self::where('is_active', true)
            ->whereNotNull('duration_minutes')
            ->distinct()
            ->orderBy('duration_minutes')
            ->pluck('duration_minutes')
            ->map(fn($v) => (int) $v)
            ->toArray();

        return $durations ?: [60];
    }

    /**
     * Label leggibile per la durata.
     */
    public static function durationLabel(int $minutes): string
    {
        return match($minutes) {
            60  => '1 ora',
            90  => '1,5 ore',
            120 => '2 ore',
            180 => '3 ore',
            default => "{$minutes} min",
        };
    }
}
