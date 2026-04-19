<?php

namespace App\Services\Flow\Modules;

use App\Services\Flow\FlowContext;
use App\Services\Flow\Module;
use App\Services\Flow\ModuleMeta;
use App\Services\Flow\ModuleResult;

/**
 * Branch condizionale basato sul valore di un campo in session.data.
 *
 * Legge una chiave (dot notation) dalla sessione e confronta con i
 * valori configurati. Ogni valore genera una porta di uscita.
 * Se nessun valore matcha, emette sulla porta "altro".
 *
 * Esempio: campo "booking_type", valori ["con_avversario", "matchmaking", "sparapalline"]
 * → 3 porte + "altro".
 */
class CondizioneCampoModule extends Module
{
    public function meta(): ModuleMeta
    {
        return new ModuleMeta(
            key: 'condizione_campo',
            label: 'Branch su campo sessione',
            category: 'logica',
            description: 'Legge un campo dalla sessione e segue la porta corrispondente al suo valore. Utile per fork condizionali (es. tipo prenotazione).',
            configSchema: [
                'campo' => [
                    'type'     => 'string',
                    'label'    => 'Campo da leggere',
                    'required' => true,
                    'help'     => 'Dot notation. Es. "booking_type", "profile.is_fit", "payment_method"',
                ],
                'valori' => [
                    'type'     => 'string_list',
                    'label'    => 'Valori possibili',
                    'required' => true,
                    'help'     => 'Ogni valore diventa una porta di uscita. Aggiungi tutti i casi.',
                ],
            ],
            icon: 'git-branch',
        );
    }

    public function outputs(): array
    {
        $valori = (array) $this->cfg('valori', []);
        $out = [];
        foreach ($valori as $v) {
            $v = (string) $v;
            if ($v === '') continue;
            $out[$this->slug($v)] = $v;
        }
        $out['altro'] = 'Altro';
        return $out;
    }

    public function execute(FlowContext $ctx): ModuleResult
    {
        $campo  = (string) $this->cfg('campo', '');
        $valori = (array) $this->cfg('valori', []);

        if ($campo === '') {
            return ModuleResult::next('altro');
        }

        $value = $ctx->get($campo);
        $valueStr = is_bool($value) ? ($value ? 'true' : 'false') : mb_strtolower(trim((string) $value));

        foreach ($valori as $v) {
            $v = (string) $v;
            if (mb_strtolower(trim($v)) === $valueStr) {
                return ModuleResult::next($this->slug($v));
            }
        }

        return ModuleResult::next('altro');
    }

    private function slug(string $s): string
    {
        $slug = \Illuminate\Support\Str::slug($s, '_');
        return $slug !== '' ? $slug : 'val_' . substr(md5($s), 0, 6);
    }
}
