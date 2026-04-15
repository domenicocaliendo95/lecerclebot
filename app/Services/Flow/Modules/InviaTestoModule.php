<?php

namespace App\Services\Flow\Modules;

use App\Services\Flow\FlowContext;
use App\Services\Flow\Module;
use App\Services\Flow\ModuleMeta;
use App\Services\Flow\ModuleResult;

/**
 * Invio messaggio di testo.
 *
 * Il testo supporta segnaposti nel formato {chiave} che vengono risolti da
 * session.data (es. {profile.name}) e da proprietà utente base ({user.name}).
 */
class InviaTestoModule extends Module
{
    public function meta(): ModuleMeta
    {
        return new ModuleMeta(
            key: 'invia_testo',
            label: 'Invia messaggio',
            category: 'invio',
            description: 'Manda un messaggio di testo al numero. Puoi usare variabili tra {graffe}, es. {user.name}.',
            configSchema: [
                'text' => [
                    'type'     => 'text',
                    'label'    => 'Testo del messaggio',
                    'required' => true,
                    'help'     => 'Max ~300 char. Variabili: {user.name}, {profile.name}, {data.requested_friendly}...',
                ],
            ],
            icon: 'send',
        );
    }

    public function execute(FlowContext $ctx): ModuleResult
    {
        $template = (string) $this->cfg('text', '');
        $text     = $this->interpolate($template, $ctx);

        return ModuleResult::next()->withSend([
            'type' => 'text',
            'text' => $text,
        ]);
    }

    private function interpolate(string $template, FlowContext $ctx): string
    {
        return preg_replace_callback('/\{([a-zA-Z0-9_.]+)\}/', function ($m) use ($ctx) {
            $key = $m[1];
            if (str_starts_with($key, 'user.')) {
                $field = substr($key, 5);
                return (string) ($ctx->user?->{$field} ?? '');
            }
            if (str_starts_with($key, 'data.')) {
                return (string) $ctx->get(substr($key, 5), '');
            }
            // default: tratta come chiave in session.data
            return (string) $ctx->get($key, '');
        }, $template) ?? $template;
    }
}
