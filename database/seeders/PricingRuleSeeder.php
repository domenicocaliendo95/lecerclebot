<?php
namespace Database\Seeders;

use App\Models\PricingRule;
use Illuminate\Database\Seeder;

/**
 * Seed delle regole di prezzo di default.
 * Configurabili dall'admin in /admin/pricing-rules.
 *
 * Struttura: giorno (null=tutti), fascia oraria, durata, prezzo
 * Le regole più specifiche (specific_date > day_of_week > null) hanno priorità più alta.
 */
class PricingRuleSeeder extends Seeder
{
    public function run(): void
    {
        PricingRule::truncate();

        $rules = [
            // ── Feriali (lun-ven = day_of_week 1-5) ──
            // Mattina 08-14
            ['label' => 'Mattina feriale 1h',    'day_of_week' => null, 'start_time' => '08:00', 'end_time' => '14:00', 'duration_minutes' => 60,   'price' => 20.00, 'price_per_hour' => 20.00, 'is_peak' => false],
            ['label' => 'Mattina feriale 1,5h',  'day_of_week' => null, 'start_time' => '08:00', 'end_time' => '14:00', 'duration_minutes' => 90,   'price' => 28.00, 'price_per_hour' => 20.00, 'is_peak' => false],
            ['label' => 'Mattina feriale 2h',    'day_of_week' => null, 'start_time' => '08:00', 'end_time' => '14:00', 'duration_minutes' => 120,  'price' => 38.00, 'price_per_hour' => 20.00, 'is_peak' => false],
            // Pomeriggio 14-18
            ['label' => 'Pomeriggio feriale 1h',   'day_of_week' => null, 'start_time' => '14:00', 'end_time' => '18:00', 'duration_minutes' => 60,  'price' => 25.00, 'price_per_hour' => 25.00, 'is_peak' => false],
            ['label' => 'Pomeriggio feriale 1,5h', 'day_of_week' => null, 'start_time' => '14:00', 'end_time' => '18:00', 'duration_minutes' => 90,  'price' => 35.00, 'price_per_hour' => 25.00, 'is_peak' => false],
            ['label' => 'Pomeriggio feriale 2h',   'day_of_week' => null, 'start_time' => '14:00', 'end_time' => '18:00', 'duration_minutes' => 120, 'price' => 46.00, 'price_per_hour' => 25.00, 'is_peak' => false],
            // Sera 18-22
            ['label' => 'Sera feriale 1h',    'day_of_week' => null, 'start_time' => '18:00', 'end_time' => '22:00', 'duration_minutes' => 60,  'price' => 30.00, 'price_per_hour' => 30.00, 'is_peak' => true],
            ['label' => 'Sera feriale 1,5h',  'day_of_week' => null, 'start_time' => '18:00', 'end_time' => '22:00', 'duration_minutes' => 90,  'price' => 42.00, 'price_per_hour' => 30.00, 'is_peak' => true],
            ['label' => 'Sera feriale 2h',    'day_of_week' => null, 'start_time' => '18:00', 'end_time' => '22:00', 'duration_minutes' => 120, 'price' => 55.00, 'price_per_hour' => 30.00, 'is_peak' => true],
            // ── Sabato (6) e Domenica (0) — prezzi maggiorati ──
            ['label' => 'Weekend mattina 1h',    'day_of_week' => 6, 'start_time' => '08:00', 'end_time' => '14:00', 'duration_minutes' => 60,  'price' => 25.00, 'price_per_hour' => 25.00, 'is_peak' => false, 'priority' => 1],
            ['label' => 'Weekend mattina 1,5h',  'day_of_week' => 6, 'start_time' => '08:00', 'end_time' => '14:00', 'duration_minutes' => 90,  'price' => 35.00, 'price_per_hour' => 25.00, 'is_peak' => false, 'priority' => 1],
            ['label' => 'Weekend pomeriggio 1h', 'day_of_week' => 6, 'start_time' => '14:00', 'end_time' => '22:00', 'duration_minutes' => 60,  'price' => 32.00, 'price_per_hour' => 32.00, 'is_peak' => true,  'priority' => 1],
            ['label' => 'Weekend pomeriggio 1,5h','day_of_week' => 6, 'start_time' => '14:00', 'end_time' => '22:00', 'duration_minutes' => 90,  'price' => 45.00, 'price_per_hour' => 32.00, 'is_peak' => true,  'priority' => 1],
            ['label' => 'Domenica mattina 1h',   'day_of_week' => 0, 'start_time' => '08:00', 'end_time' => '14:00', 'duration_minutes' => 60,  'price' => 25.00, 'price_per_hour' => 25.00, 'is_peak' => false, 'priority' => 1],
            ['label' => 'Domenica pomeriggio 1h','day_of_week' => 0, 'start_time' => '14:00', 'end_time' => '22:00', 'duration_minutes' => 60,  'price' => 32.00, 'price_per_hour' => 32.00, 'is_peak' => true,  'priority' => 1],
        ];

        foreach ($rules as $rule) {
            PricingRule::create(array_merge(['is_active' => true, 'priority' => $rule['priority'] ?? 0], $rule));
        }

        $this->command->info('Pricing rules create: ' . count($rules));
    }
}
