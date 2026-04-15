<?php

namespace App\Services\Flow\Modules;

use App\Services\Flow\FlowContext;
use App\Services\Flow\Module;
use App\Services\Flow\ModuleMeta;
use App\Services\Flow\ModuleResult;
use App\Services\GeminiService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Classifica un testo libero con Gemini in una di N categorie.
 *
 * Config:
 *  - input_source: "input" | chiave data.X (default: "input")
 *  - prompt: istruzioni per il modello (viene automaticamente accoppiato
 *    alla lista categorie)
 *  - categorie: array di stringhe, una porta di output per ciascuna
 *
 * Porte di output: una per categoria (slug), più "fallback".
 *
 * Esempio d'uso: utente scrive "vorrei prenotare domani" → classifica in
 * {prenotazione, matchmaking, info} → segue la porta "prenotazione".
 */
class GeminiClassificaModule extends Module
{
    public function meta(): ModuleMeta
    {
        return new ModuleMeta(
            key: 'gemini_classifica',
            label: 'Classifica con Gemini',
            category: 'ai',
            description: 'Chiede a Gemini di classificare un testo in una lista di categorie. Ogni categoria è una porta di uscita.',
            configSchema: [
                'input_source' => [
                    'type'    => 'string',
                    'label'   => 'Cosa classificare',
                    'default' => 'input',
                    'help'    => '"input" = ultimo messaggio utente. Oppure una chiave di session.data (es. "user_reply").',
                ],
                'prompt' => [
                    'type'     => 'text',
                    'label'    => 'Istruzioni per il modello',
                    'required' => true,
                    'default'  => "Classifica l'intento dell'utente in una delle categorie elencate. Rispondi SOLO con il nome esatto della categoria.",
                ],
                'categorie' => [
                    'type'     => 'string_list',
                    'label'    => 'Categorie',
                    'required' => true,
                    'help'     => 'Ogni categoria genera una porta di uscita. Usa nomi brevi e distinti.',
                ],
            ],
            icon: 'sparkles',
        );
    }

    public function outputs(): array
    {
        $cats = (array) $this->cfg('categorie', []);
        $out  = [];
        foreach ($cats as $cat) {
            $cat = (string) $cat;
            if ($cat === '') continue;
            $out[$this->slug($cat)] = $cat;
        }
        $out['fallback'] = 'Non classificato';
        return $out;
    }

    public function execute(FlowContext $ctx): ModuleResult
    {
        $source = (string) $this->cfg('input_source', 'input');
        $text   = $source === 'input' ? $ctx->input : (string) $ctx->get($source, '');
        $cats   = array_values(array_filter(array_map('strval', (array) $this->cfg('categorie', []))));

        if ($text === '' || empty($cats)) {
            return ModuleResult::next('fallback');
        }

        $prompt = (string) $this->cfg('prompt', '');
        $full   = $prompt
            . "\n\nCategorie disponibili:\n- " . implode("\n- ", $cats)
            . "\n\nTesto utente:\n\"{$text}\"\n\nRisposta (solo il nome della categoria):";

        try {
            $gemini   = app(GeminiService::class);
            $response = trim($gemini->generate($full));
        } catch (\Throwable $e) {
            Log::warning('gemini_classifica failed', ['error' => $e->getMessage()]);
            return ModuleResult::next('fallback')->withData(['ai_response' => null]);
        }

        $matched = $this->pickCategory($response, $cats);
        if ($matched === null) {
            return ModuleResult::next('fallback')->withData(['ai_response' => $response]);
        }

        return ModuleResult::next($this->slug($matched))
            ->withData(['ai_response' => $response, 'ai_classification' => $matched]);
    }

    private function pickCategory(string $raw, array $cats): ?string
    {
        $norm = mb_strtolower(trim($raw));
        foreach ($cats as $cat) {
            if (mb_strtolower($cat) === $norm) {
                return $cat;
            }
        }
        foreach ($cats as $cat) {
            if (str_contains($norm, mb_strtolower($cat))) {
                return $cat;
            }
        }
        return null;
    }

    private function slug(string $s): string
    {
        $slug = Str::slug($s, '_');
        return $slug !== '' ? $slug : 'cat_' . substr(md5($s), 0, 6);
    }
}
