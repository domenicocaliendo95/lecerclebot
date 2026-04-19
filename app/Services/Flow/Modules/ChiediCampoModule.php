<?php

namespace App\Services\Flow\Modules;

use App\Services\Flow\FlowContext;
use App\Services\Flow\Module;
use App\Services\Flow\ModuleMeta;
use App\Services\Flow\ModuleResult;

/**
 * Macro "chiedi un campo, valida, salva" — il mattone dell'onboarding.
 *
 * Fase 1 (prima entrata): manda la domanda, mette in wait.
 * Fase 2 (resume con input):
 *   - valida l'input secondo il validator configurato
 *   - se ok: salva in session.data sotto save_to, emette "ok"
 *   - se ko: rimanda il messaggio di riprova, resta in wait sullo stesso nodo
 *
 * Un singolo nodo rappresenta un'intera "slot" di onboarding.
 */
class ChiediCampoModule extends Module
{
    public function meta(): ModuleMeta
    {
        return new ModuleMeta(
            key: 'chiedi_campo',
            label: 'Chiedi e valida un campo',
            category: 'attesa',
            description: 'Manda una domanda, attende la risposta, la valida e la salva in session.data. In caso di errore chiede di riprovare automaticamente.',
            configSchema: [
                'question' => [
                    'type'     => 'text',
                    'label'    => 'Domanda',
                    'required' => true,
                ],
                'save_to' => [
                    'type'     => 'string',
                    'label'    => 'Salva in',
                    'required' => true,
                    'help'     => 'Es. "profile.name", "profile.age", "data.note". Dot notation supportata.',
                ],
                'validator' => [
                    'type'    => 'select',
                    'label'   => 'Validazione',
                    'default' => 'any',
                    'options' => [
                        ['value' => 'any',     'label' => 'Qualunque testo'],
                        ['value' => 'name',    'label' => 'Nome di persona'],
                        ['value' => 'integer', 'label' => 'Numero intero'],
                        ['value' => 'date',    'label' => 'Data (gg/mm/aaaa)'],
                        ['value' => 'email',   'label' => 'Email'],
                        ['value' => 'regex',   'label' => 'Espressione regolare'],
                    ],
                ],
                'min' => [
                    'type'  => 'int',
                    'label' => 'Minimo (solo integer)',
                ],
                'max' => [
                    'type'  => 'int',
                    'label' => 'Massimo (solo integer)',
                ],
                'pattern' => [
                    'type'  => 'string',
                    'label' => 'Pattern (solo regex)',
                    'help'  => 'Regex PCRE senza delimitatori. Es: ^([1-4]\\.[1-6]|NC)$',
                ],
                'retry_message' => [
                    'type'    => 'text',
                    'label'   => 'Messaggio se non valido',
                    'default' => 'Non ho capito, puoi riprovare?',
                ],
            ],
            icon: 'message-circle-question',
        );
    }

    public function outputs(): array
    {
        return [
            'ok' => 'Valido',
        ];
    }

    public function execute(FlowContext $ctx): ModuleResult
    {
        if (!$ctx->resuming) {
            $text = $this->interpolate((string) $this->cfg('question', ''), $ctx);
            return ModuleResult::wait(send: [[
                'type' => 'text',
                'text' => $text,
            ]]);
        }

        $input = trim($ctx->input);
        $validated = $this->validate($input);

        if ($validated === null) {
            $retry = $this->interpolate((string) $this->cfg('retry_message', 'Non ho capito, puoi riprovare?'), $ctx);
            return ModuleResult::wait(send: [[
                'type' => 'text',
                'text' => $retry,
            ]]);
        }

        $saveTo = (string) $this->cfg('save_to', '');
        if ($saveTo !== '') {
            $ctx->session->mergeData($this->nestedSet($saveTo, $validated, $ctx->session->data ?? []));
        }

        return ModuleResult::next('ok');
    }

    /**
     * Applica la validazione e ritorna il valore pulito, o null se invalido.
     */
    private function validate(string $input): mixed
    {
        $type = (string) $this->cfg('validator', 'any');

        if ($input === '') {
            return null;
        }

        return match ($type) {
            'name'    => $this->validateName($input),
            'integer' => $this->validateInteger($input),
            'date'    => $this->validateDate($input),
            'email'   => $this->validateEmail($input),
            'regex'   => $this->validateRegex($input),
            default   => $input,
        };
    }

    private function validateName(string $input): ?string
    {
        if (!preg_match('/^[\p{L}\s\'\-]{2,60}$/u', $input)) {
            return null;
        }
        return mb_convert_case($input, MB_CASE_TITLE, 'UTF-8');
    }

    private function validateInteger(string $input): ?int
    {
        if (!preg_match('/-?\d+/', $input, $m)) {
            return null;
        }
        $n = (int) $m[0];
        $min = $this->cfg('min');
        $max = $this->cfg('max');
        if ($min !== null && $n < (int) $min) return null;
        if ($max !== null && $n > (int) $max) return null;
        return $n;
    }

    private function validateDate(string $input): ?string
    {
        // Accetta: dd/mm/yyyy, dd-mm-yyyy, dd.mm.yyyy, yyyy-mm-dd
        if (preg_match('/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{4})$/', trim($input), $m)) {
            $day = (int) $m[1]; $month = (int) $m[2]; $year = (int) $m[3];
            if (checkdate($month, $day, $year)) {
                return sprintf('%04d-%02d-%02d', $year, $month, $day);
            }
        }
        if (preg_match('/^(\d{4})[\/\-.](\d{1,2})[\/\-.](\d{1,2})$/', trim($input), $m)) {
            $year = (int) $m[1]; $month = (int) $m[2]; $day = (int) $m[3];
            if (checkdate($month, $day, $year)) {
                return sprintf('%04d-%02d-%02d', $year, $month, $day);
            }
        }
        return null;
    }

    private function validateEmail(string $input): ?string
    {
        $email = mb_strtolower(trim($input));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
            return $email;
        }
        return null;
    }

    private function validateRegex(string $input): ?string
    {
        $pattern = (string) $this->cfg('pattern', '');
        if ($pattern === '') {
            return $input;
        }
        $delimited = '/' . str_replace('/', '\/', $pattern) . '/iu';
        if (!@preg_match($delimited, $input, $m)) {
            return null;
        }
        return $m[1] ?? $m[0];
    }

    /**
     * Costruisce un diff parziale per mergeData, rispettando la dot notation.
     * Es. ('profile.name', 'Mario', {profile: {age: 20}}) → {profile: {age:20, name:'Mario'}}
     */
    private function nestedSet(string $path, mixed $value, array $current): array
    {
        $keys = explode('.', $path);
        if (count($keys) === 1) {
            return [$keys[0] => $value];
        }

        // Merge con quanto già presente per non cancellare fratelli
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
