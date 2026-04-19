<?php

namespace App\Console\Commands;

use App\Services\Flow\FlowContext;
use App\Services\Flow\ModuleRegistry;
use App\Models\BotSession;
use App\Models\FlowNode;
use Illuminate\Console\Command;

/**
 * Testa un singolo modulo in isolamento con input controllato.
 *
 * Esempi:
 *   # Test parser risultato
 *   php artisan flow:test-module parse_risultato "6-1 6-2"
 *   php artisan flow:test-module parse_risultato "Ho vinto"
 *   php artisan flow:test-module parse_risultato "61-62-76(4)"
 *
 *   # Test parser data
 *   php artisan flow:test-module parse_data "domani alle 17.30"
 *   php artisan flow:test-module parse_data "sabato 9 e mezza"
 *
 *   # Test classificatore Gemini
 *   php artisan flow:test-module gemini_classifica "vorrei prenotare" \
 *     --config='{"categorie":["prenotazione","info","altro"],"prompt":"Classifica intento"}'
 *
 *   # Test condizione campo
 *   php artisan flow:test-module condizione_campo "" \
 *     --config='{"campo":"booking_type","valori":["matchmaking","con_avversario"]}' \
 *     --data='{"booking_type":"matchmaking"}'
 */
class FlowTestModule extends Command
{
    protected $signature = 'flow:test-module
                            {module_key : Chiave del modulo da testare}
                            {input : Input simulato}
                            {--config= : Config JSON del modulo (opzionale)}
                            {--data= : Session data JSON (opzionale)}
                            {--resuming : Simula resume dopo wait}';

    protected $description = 'Testa un singolo modulo del flusso in isolamento con input controllato';

    public function handle(ModuleRegistry $registry): int
    {
        $key    = (string) $this->argument('module_key');
        $input  = (string) $this->argument('input');
        $config = $this->option('config') ? json_decode($this->option('config'), true) ?? [] : [];
        $data   = $this->option('data') ? json_decode($this->option('data'), true) ?? [] : [];

        $module = $registry->instantiate($key, $config);
        if (!$module) {
            $this->error("Modulo '{$key}' non trovato nel registry.");
            $this->line('Moduli disponibili:');
            foreach ($registry->builtInList() as $m) {
                $this->line("  {$m['key']} ({$m['category']})");
            }
            return self::FAILURE;
        }

        $meta = $module->meta();
        $this->line("┌─ Test: {$meta->label} ({$meta->key})");
        $this->line("│  Input: \"{$input}\"");
        if (!empty($config)) $this->line("│  Config: " . json_encode($config, JSON_UNESCAPED_UNICODE));
        if (!empty($data)) $this->line("│  Data: " . json_encode($data, JSON_UNESCAPED_UNICODE));

        // Crea sessione e contesto finti
        $session = new BotSession();
        $session->phone = '+39TEST';
        $session->channel = 'test';
        $session->external_id = 'test';
        $session->state = 'NEW';
        $session->data = array_merge(['last_input' => $input], $data);

        $node = new FlowNode();
        $node->id = 0;
        $node->module_key = $key;
        $node->config = $config;

        $ctx = new FlowContext(
            session:    $session,
            channel:    'test',
            externalId: 'test',
            input:      $input,
            user:       null,
            node:       $node,
            resuming:   (bool) $this->option('resuming'),
        );

        try {
            $result = $module->execute($ctx);
        } catch (\Throwable $e) {
            $this->error("│  ERRORE: {$e->getMessage()}");
            return self::FAILURE;
        }

        $this->line("├─ Porta uscita: {$result->next}");
        $this->line("│  Wait: " . ($result->wait ? 'sì' : 'no'));

        if (!empty($result->data)) {
            $this->line("│  Data output:");
            foreach ($result->data as $k => $v) {
                $this->line("│    {$k}: " . json_encode($v, JSON_UNESCAPED_UNICODE));
            }
        }

        if (!empty($result->send)) {
            foreach ($result->send as $msg) {
                $this->line("│  Messaggio: [{$msg['type']}] " . ($msg['text'] ?? ''));
                if (!empty($msg['buttons'])) {
                    foreach ($msg['buttons'] as $b) {
                        $this->line("│    · {$b}");
                    }
                }
            }
        }

        if ($result->descendCompositeId !== null) $this->line("│  Descend composito: {$result->descendCompositeId}");
        if ($result->ascendPort !== null) $this->line("│  Ascend porta: {$result->ascendPort}");

        $this->line("└─ ✓ OK");

        return self::SUCCESS;
    }
}
