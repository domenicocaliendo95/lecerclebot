<?php

namespace App\Services\Flow\Modules;

use App\Services\Flow\FlowContext;
use App\Services\Flow\Module;
use App\Services\Flow\ModuleMeta;
use App\Services\Flow\ModuleResult;

/**
 * Scrive uno o più valori in session.data.
 *
 * Uso tipico: dopo un click su un bottone, salvare la scelta in una chiave
 * (es. data.booking_type=matchmaking) prima di proseguire. L'alternativa è
 * mettere la logica sui successivi moduli.
 *
 * Config `assignments` è una lista di {key, value}. Supporta dot notation
 * nella chiave (profile.slot, data.booking_type) e interpolazione sul valore.
 */
class SalvaInSessioneModule extends Module
{
    public function meta(): ModuleMeta
    {
        return new ModuleMeta(
            key: 'salva_in_sessione',
            label: 'Salva in sessione',
            category: 'dati',
            description: 'Scrive uno o più valori in session.data. Comodo per registrare la scelta dopo un bottone.',
            configSchema: [
                'assignments' => [
                    'type'     => 'key_value',
                    'label'    => 'Assegnazioni',
                    'required' => true,
                    'help'     => 'Lista chiave→valore. Es. profile.slot=mattina. Usa {user.name} per interpolare.',
                ],
            ],
            icon: 'edit',
        );
    }

    public function execute(FlowContext $ctx): ModuleResult
    {
        $assignments = (array) $this->cfg('assignments', []);
        $patch = [];

        foreach ($assignments as $row) {
            $key = is_array($row) ? (string) ($row['key'] ?? '') : '';
            $val = is_array($row) ? ($row['value'] ?? null) : null;
            if ($key === '') continue;

            if (is_string($val)) {
                $val = $this->interpolate($val, $ctx);
            }

            $patch = array_replace_recursive($patch, $this->nestedSet($key, $val, $ctx->session->data ?? []));
        }

        if (!empty($patch)) {
            $ctx->session->mergeData($patch);
        }

        return ModuleResult::next();
    }

    private function nestedSet(string $path, mixed $value, array $current): array
    {
        $keys = explode('.', $path);
        if (count($keys) === 1) {
            return [$keys[0] => $value];
        }
        $root = $keys[0];
        $rest = array_slice($keys, 1);
        $existing = $current[$root] ?? [];
        if (!is_array($existing)) {
            $existing = [];
        }
        $cursor = &$existing;
        foreach ($rest as $i => $k) {
            if ($i === count($rest) - 1) {
                $cursor[$k] = $value;
            } else {
                if (!isset($cursor[$k]) || !is_array($cursor[$k])) {
                    $cursor[$k] = [];
                }
                $cursor = &$cursor[$k];
            }
        }
        return [$root => $existing];
    }

    private function interpolate(string $template, FlowContext $ctx): string
    {
        return preg_replace_callback('/\{([a-zA-Z0-9_.]+)\}/', function ($m) use ($ctx) {
            $key = $m[1];
            if (str_starts_with($key, 'user.')) {
                return (string) ($ctx->user?->{substr($key, 5)} ?? '');
            }
            if (str_starts_with($key, 'data.')) {
                return (string) $ctx->get(substr($key, 5), '');
            }
            return (string) $ctx->get($key, '');
        }, $template) ?? $template;
    }
}
