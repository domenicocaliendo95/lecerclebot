<?php

namespace App\Services\Flow\Modules;

use App\Services\Bot\TextGenerator;
use App\Services\Flow\FlowContext;
use App\Services\Flow\Module;
use App\Services\Flow\ModuleMeta;
use App\Services\Flow\ModuleResult;
use Illuminate\Support\Facades\Log;

/**
 * Parsa un input in linguaggio naturale come data/ora.
 *
 * Sorgente: per default l'ultimo input dell'utente ($ctx->input), oppure una
 * chiave in session.data (es. "user_reply") se impostata.
 *
 * Delega al TextGenerator esistente: parser locale (domani, sabato mattina,
 * 28/03…) + fallback Gemini per input complessi.
 *
 * Scrive in session.data:
 *  - requested_date (YYYY-MM-DD)
 *  - requested_time (HH:MM) o null
 *  - requested_friendly (testo umano)
 *  - requested_raw
 *
 * Porte: "ok" se parsing riuscito, "errore" altrimenti.
 */
class ParseDataModule extends Module
{
    public function meta(): ModuleMeta
    {
        return new ModuleMeta(
            key: 'parse_data',
            label: 'Interpreta data/ora',
            category: 'dati',
            description: 'Prende un testo in linguaggio naturale e lo converte in data + ora. Salva il risultato in session.data.requested_*.',
            configSchema: [
                'source' => [
                    'type'    => 'string',
                    'label'   => 'Sorgente',
                    'default' => 'last_input',
                    'help'    => 'Chiave in session.data. Default "last_input" (ultimo messaggio utente salvato dal runner).',
                ],
            ],
            icon: 'calendar-clock',
        );
    }

    public function outputs(): array
    {
        return [
            'ok'     => 'Riconosciuto',
            'errore' => 'Non capito',
        ];
    }

    public function execute(FlowContext $ctx): ModuleResult
    {
        $source = (string) $this->cfg('source', 'last_input');
        $text   = $source === 'input' ? $ctx->input : (string) $ctx->get($source, '');

        if (trim($text) === '') {
            return ModuleResult::next('errore');
        }

        try {
            $parsed = app(TextGenerator::class)->parseDateTime($text);
        } catch (\Throwable $e) {
            Log::warning('parse_data failed', ['error' => $e->getMessage()]);
            return ModuleResult::next('errore');
        }

        if ($parsed === null) {
            return ModuleResult::next('errore')->withData(['date_parsed' => false]);
        }

        return ModuleResult::next('ok')->withData([
            'requested_date'     => $parsed['date'] ?? null,
            'requested_time'     => $parsed['time'] ?? null,
            'requested_friendly' => $parsed['friendly'] ?? null,
            'requested_raw'      => $text,
            'date_parsed'        => true,
        ]);
    }
}
