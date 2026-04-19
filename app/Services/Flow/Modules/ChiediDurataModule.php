<?php

namespace App\Services\Flow\Modules;

use App\Models\PricingRule;
use App\Services\Flow\FlowContext;
use App\Services\Flow\Module;
use App\Services\Flow\ModuleMeta;
use App\Services\Flow\ModuleResult;

/**
 * Chiede la durata della prenotazione mostrando SOLO le durate
 * configurate nelle PricingRule. Se ne esiste solo una, la seleziona
 * automaticamente senza chiedere.
 *
 * Fase 1: legge PricingRule::availableDurations(), genera bottoni
 * Fase 2 (resume): matcha la selezione, salva requested_duration_minutes
 */
class ChiediDurataModule extends Module
{
    public function meta(): ModuleMeta
    {
        return new ModuleMeta(
            key: 'chiedi_durata',
            label: 'Chiedi durata (da listino)',
            category: 'attesa',
            description: 'Mostra le durate disponibili dal listino prezzi come bottoni. Se ne esiste solo una, la seleziona in automatico.',
            configSchema: [
                'text' => [
                    'type'    => 'text',
                    'label'   => 'Domanda',
                    'default' => 'Quanto vuoi giocare?',
                ],
            ],
            icon: 'clock',
        );
    }

    public function outputs(): array
    {
        return ['ok' => 'Durata selezionata'];
    }

    public function execute(FlowContext $ctx): ModuleResult
    {
        $durations = PricingRule::availableDurations();
        if (empty($durations)) {
            $durations = [60]; // fallback
        }

        // Se una sola durata disponibile → seleziona automaticamente
        if (count($durations) === 1) {
            return ModuleResult::next('ok')->withData([
                'requested_duration_minutes' => $durations[0],
            ]);
        }

        // Resume: matcha la selezione
        if ($ctx->resuming) {
            $input = mb_strtolower(trim($ctx->input));
            foreach ($durations as $min) {
                $label = mb_strtolower($this->durationLabel($min));
                if (str_contains($input, $label) || str_contains($label, $input)) {
                    return ModuleResult::next('ok')->withData([
                        'requested_duration_minutes' => $min,
                    ]);
                }
            }
            // Prova per posizione (1, 2, 3)
            if (preg_match('/^(\d)$/', $input, $m)) {
                $idx = (int) $m[1] - 1;
                if (isset($durations[$idx])) {
                    return ModuleResult::next('ok')->withData([
                        'requested_duration_minutes' => $durations[$idx],
                    ]);
                }
            }
            // Fallback: primo
            return ModuleResult::next('ok')->withData([
                'requested_duration_minutes' => $durations[0],
            ]);
        }

        // Prima entrata: mostra bottoni con prezzi
        $text = (string) ($this->cfg('text', 'Quanto vuoi giocare?') ?: 'Quanto vuoi giocare?');

        $startTime = null;
        $date = $ctx->get('requested_date');
        $time = $ctx->get('requested_time');
        if ($date && $time) {
            $startTime = \Carbon\Carbon::parse("{$date} {$time}", 'Europe/Rome');
        }

        $buttons = [];
        foreach (array_slice($durations, 0, 3) as $min) {
            $label = $this->durationLabel($min);
            if ($startTime) {
                $price = PricingRule::getPriceForSlot($startTime, $min);
                $label .= " - €{$price}";
            }
            if (mb_strlen($label) > 20) {
                $label = mb_substr($label, 0, 20);
            }
            $buttons[] = $label;
        }

        return ModuleResult::wait(send: [[
            'type'    => 'buttons',
            'text'    => $text,
            'buttons' => $buttons,
        ]]);
    }

    private function durationLabel(int $minutes): string
    {
        if ($minutes === 60) return '1 ora';
        if ($minutes === 90) return '1 ora e mezza';
        if ($minutes === 120) return '2 ore';
        if ($minutes === 180) return '3 ore';
        if ($minutes % 60 === 0) return ($minutes / 60) . ' ore';
        return "{$minutes} min";
    }
}
