<?php

namespace App\Services\Flow\Modules;

use App\Services\Flow\FlowContext;
use App\Services\Flow\Module;
use App\Services\Flow\ModuleMeta;
use App\Services\Flow\ModuleResult;

/**
 * Invia messaggio con bottoni e attende la risposta.
 *
 * Fase 1 (prima entrata): spedisce testo + bottoni e mette in wait.
 * Fase 2 (resuming): matcha l'input utente (esatto/substring, case-insensitive)
 * contro le label dei bottoni. Emette la porta del bottone matchato, oppure
 * "fallback" se niente matcha.
 *
 * Le porte di output sono dinamiche: una per bottone (btn_0, btn_1, ...) più
 * la porta "fallback". L'editor visualizza le porte leggendo outputs().
 */
class InviaBottoniModule extends Module
{
    public function meta(): ModuleMeta
    {
        return new ModuleMeta(
            key: 'invia_bottoni',
            label: 'Invia bottoni',
            category: 'invio',
            description: 'Manda un messaggio con fino a 3 bottoni e aspetta la scelta. Ogni bottone ha la sua porta di uscita.',
            configSchema: [
                'text' => [
                    'type'     => 'text',
                    'label'    => 'Testo del messaggio',
                    'required' => true,
                ],
                'buttons' => [
                    'type'     => 'button_list',
                    'label'    => 'Bottoni (max 3)',
                    'required' => true,
                    'max'      => 3,
                    'help'     => 'Label max 20 caratteri. Ogni bottone genera una porta di uscita.',
                ],
            ],
            icon: 'menu',
        );
    }

    public function outputs(): array
    {
        $buttons = (array) $this->cfg('buttons', []);
        $out = [];
        foreach (array_values($buttons) as $i => $btn) {
            $label = is_array($btn) ? ($btn['label'] ?? "Opzione " . ($i + 1)) : (string) $btn;
            $out["btn_{$i}"] = $label;
        }
        $out['fallback'] = 'Nessun match';
        return $out;
    }

    public function execute(FlowContext $ctx): ModuleResult
    {
        $buttons = (array) $this->cfg('buttons', []);
        $labels  = [];
        foreach (array_values($buttons) as $btn) {
            $labels[] = is_array($btn) ? (string) ($btn['label'] ?? '') : (string) $btn;
        }

        // Fase 2: l'utente sta rispondendo — prova a matchare.
        if ($ctx->resuming && $ctx->input !== '') {
            $port = $this->matchButton($ctx->input, $labels);
            if ($port !== null) {
                return ModuleResult::next($port);
            }
            // Nessun match: manda alla porta fallback.
            return ModuleResult::next('fallback');
        }

        // Fase 1: prima entrata — invia i bottoni e metti in wait.
        $text = $this->interpolate((string) $this->cfg('text', ''), $ctx);
        $visible = array_slice($labels, 0, 3);

        return ModuleResult::wait(send: [[
            'type'    => 'buttons',
            'text'    => $text,
            'buttons' => $visible,
        ]]);
    }

    private function matchButton(string $input, array $labels): ?string
    {
        $norm = mb_strtolower(trim($input));
        foreach ($labels as $i => $label) {
            $l = mb_strtolower(trim($label));
            if ($l === '' || $i > 2) {
                continue;
            }
            if ($norm === $l || str_contains($norm, $l) || str_contains($l, $norm)) {
                return "btn_{$i}";
            }
        }
        return null;
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
