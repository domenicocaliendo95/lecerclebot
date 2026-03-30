<?php

namespace Database\Seeders;

use App\Models\PricingRule;
use Illuminate\Database\Seeder;

class PricingRuleSeeder extends Seeder
{
    public function run(): void
    {
        // Pulisce le regole esistenti
        PricingRule::truncate();

        $rules = [
            [
                'day_of_week'    => null,   // tutti i giorni
                'start_time'     => '08:00',
                'end_time'       => '14:00',
                'price_per_hour' => 20.00,
                'is_peak'        => false,
                'label'          => 'Mattina (08:00–14:00)',
            ],
            [
                'day_of_week'    => null,
                'start_time'     => '14:00',
                'end_time'       => '18:00',
                'price_per_hour' => 25.00,
                'is_peak'        => false,
                'label'          => 'Pomeriggio (14:00–18:00)',
            ],
            [
                'day_of_week'    => null,
                'start_time'     => '18:00',
                'end_time'       => '22:00',
                'price_per_hour' => 30.00,
                'is_peak'        => true,
                'label'          => 'Sera/Peak (18:00–22:00)',
            ],
        ];

        foreach ($rules as $rule) {
            PricingRule::create($rule);
        }
    }
}
