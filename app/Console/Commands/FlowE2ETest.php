<?php

namespace App\Console\Commands;

use App\Models\BotSession;
use App\Models\FlowNode;
use App\Services\Bot\TextGenerator;
use App\Services\Flow\FlowContext;
use App\Services\Flow\FlowRunner;
use App\Services\Flow\ModuleRegistry;
use Illuminate\Console\Command;

/**
 * Test E2E completo del FlowRunner su tutti gli scenari principali.
 *
 * Uso:
 *   php artisan flow:e2e-test
 */
class FlowE2ETest extends Command
{
    protected $signature = 'flow:e2e-test';
    protected $description = 'Esegue test end-to-end su onboarding, prenotazione, parser e keyword';

    private int $passed = 0;
    private int $failed = 0;
    private array $errors = [];
    private string $phone;

    public function handle(FlowRunner $runner, TextGenerator $textGen, ModuleRegistry $registry): int
    {
        $this->phone = '+39TEST' . time();

        $this->line("\n══════════════════════════════════════");
        $this->line("  FLOW E2E TEST SUITE");
        $this->line("══════════════════════════════════════\n");

        $this->testOnboarding($runner);
        $this->testPrenotazione($runner);
        $this->testParserDate($textGen);
        $this->testParserRisultati($registry);
        $this->testKeyword($runner);
        $this->testMatchmakingModules($registry);

        $this->line("\n══════════════════════════════════════");
        $this->line("  RISULTATI: {$this->passed} ✅  {$this->failed} ❌");
        $this->line("══════════════════════════════════════\n");

        if (!empty($this->errors)) {
            $this->error("ERRORI:");
            foreach ($this->errors as $i => $e) {
                $this->line("  " . ($i + 1) . ". {$e}");
            }
        }

        // Pulizia
        BotSession::where('channel', 'whatsapp')->where('external_id', $this->phone)->delete();

        return $this->failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function sim(FlowRunner $runner, string $input): array
    {
        return $runner->simulate('whatsapp', $this->phone, $input);
    }

    private function allText(array $queue): string
    {
        return implode(' | ', array_map(fn($m) => $m['text'] ?? '', $queue));
    }

    private function assertContains(string $needle, array $queue, string $label): void
    {
        if (stripos($this->allText($queue), $needle) !== false) {
            $this->passed++;
            $this->line("  ✅ {$label}");
        } else {
            $this->failed++;
            $this->errors[] = "{$label}: cercavo '{$needle}', trovato: " . mb_substr($this->allText($queue), 0, 120);
            $this->line("  ❌ {$label}");
            $this->line("     trovato: " . mb_substr($this->allText($queue), 0, 100));
        }
    }

    private function assertAny(array $needles, array $queue, string $label): void
    {
        $text = $this->allText($queue);
        foreach ($needles as $n) {
            if (stripos($text, $n) !== false) {
                $this->passed++;
                $this->line("  ✅ {$label}");
                return;
            }
        }
        $this->failed++;
        $this->errors[] = "{$label}: nessuno di [" . implode(', ', $needles) . "] trovato in: " . mb_substr($text, 0, 120);
        $this->line("  ❌ {$label}");
        $this->line("     trovato: " . mb_substr($text, 0, 100));
    }

    private function testOnboarding(FlowRunner $runner): void
    {
        $this->info("── 1. Onboarding ──");

        BotSession::where('channel', 'whatsapp')->where('external_id', $this->phone)->delete();

        $q = $this->sim($runner, 'ciao');
        $this->assertAny(['Come ti chiami', 'nome'], $q, '1.1 Trigger → chiedi nome');

        $q = $this->sim($runner, 'Mario Rossi');
        $this->assertAny(['tesserato', 'FIT'], $q, '1.2 Nome → chiedi FIT');

        $q = $this->sim($runner, 'Non sono tesserato');
        $this->assertAny(['livello', 'giochi'], $q, '1.3 FIT no → chiedi livello');

        $q = $this->sim($runner, 'Dilettante');
        $this->assertAny(['nato', 'nascita', 'anni', 'età'], $q, '1.4 Livello → chiedi nascita/età');

        $q = $this->sim($runner, 'salta');
        $this->assertAny(['email', 'fascia', 'quando giochi', 'slot', 'preferisci'], $q, '1.5 Nascita salta → email/slot');

        // Completa onboarding
        $q = $this->sim($runner, 'salta');   // email
        $q = $this->sim($runner, 'Mattina'); // slot

        // Verifica che arriviamo al menu
        $session = BotSession::where('channel', 'whatsapp')->where('external_id', $this->phone)->first();
        $node = $session?->current_node_id ? FlowNode::find($session->current_node_id) : null;
        $this->line("  ℹ️  node: " . ($node?->label ?? 'null') . " (id: " . ($session?->current_node_id ?? '-') . ")");

        // Prova ad arrivare al menu
        $text = $this->allText($q);
        if (stripos($text, 'cosa vuoi fare') === false && stripos($text, 'menu') === false) {
            $q = $this->sim($runner, 'Mattina'); // retry
        }
    }

    private function testPrenotazione(FlowRunner $runner): void
    {
        $this->info("\n── 2. Prenotazione ──");

        $q = $this->sim($runner, 'Prenota un campo');
        $this->assertAny(['avversario', 'chi gioc', 'quando', 'giocare'], $q, '2.1 Menu → avversario/quando');

        // Testa keyword menu per tornare indietro
        $q = $this->sim($runner, 'menu');
        $this->assertAny(['cosa vuoi fare', 'Prenota', 'avversario'], $q, '2.2 Keyword menu funziona');
    }

    private function testParserDate(TextGenerator $textGen): void
    {
        $this->info("\n── 3. Parser date ──");

        $cases = [
            ['domani alle 17.30', '17:30'],
            ['domani alle 9:30',  '09:30'],
            ['sabato alle 19',   '19:00'],
            ['domani alle 9 e mezza', '09:30'],
            ['oggi pomeriggio',  '15:00'],
            ['domani 17',        '17:00'],
            ['lunedì alle 10',   '10:00'],
        ];

        foreach ($cases as [$input, $expectedTime]) {
            $result = $textGen->parseDateTime($input);
            $got = $result ? ($result['time'] ?? 'null') : 'null';
            if ($got === $expectedTime) {
                $this->passed++;
                $this->line("  ✅ \"{$input}\" → {$got}");
            } else {
                $this->failed++;
                $this->errors[] = "Parser data \"{$input}\": atteso {$expectedTime}, ottenuto {$got}";
                $this->line("  ❌ \"{$input}\" → atteso {$expectedTime}, ottenuto {$got}");
            }
        }
    }

    private function testParserRisultati(ModuleRegistry $registry): void
    {
        $this->info("\n── 4. Parser risultati ──");

        $cases = [
            ['6-1 6-2',          'ok',          'won',        'standard'],
            ['Ho vinto',         'ok',          'won',        'dichiarazione'],
            ['ho perso 3-6 4-6', 'ok',          'lost',       'perso + score'],
            ['non giocata',      'non_giocata', 'not_played', 'non giocata'],
            ['61-62',            'ok',          'won',        'compresso'],
            ['7-6(4) 3-6 6-2',  'ok',          'won',        'con tiebreak'],
            ['Ho perso',         'ok',          'lost',       'perso senza score'],
        ];

        foreach ($cases as [$input, $expectedPort, $expectedResult, $label]) {
            $mod = $registry->instantiate('parse_risultato', ['source' => 'input']);
            if (!$mod) {
                $this->failed++;
                $this->errors[] = "Modulo parse_risultato non trovato";
                $this->line("  ❌ Modulo parse_risultato non trovato");
                return;
            }

            $session = new BotSession();
            $session->data = ['last_input' => $input];

            $node = new \App\Models\FlowNode();
            $node->id = 0;
            $node->module_key = 'parse_risultato';
            $node->config = ['source' => 'input'];

            $ctx = new FlowContext(
                session: $session, channel: 'test', externalId: 'test',
                input: $input, user: null, node: $node,
            );

            $result = $mod->execute($ctx);
            $port = $result->next;
            $matchResult = $result->data['match_result'] ?? '?';

            if ($port === $expectedPort && $matchResult === $expectedResult) {
                $this->passed++;
                $this->line("  ✅ \"{$input}\" → {$port}/{$matchResult} ({$label})");
            } else {
                $this->failed++;
                $this->errors[] = "Risultato \"{$input}\": atteso {$expectedPort}/{$expectedResult}, ottenuto {$port}/{$matchResult}";
                $this->line("  ❌ \"{$input}\" → atteso {$expectedPort}/{$expectedResult}, ottenuto {$port}/{$matchResult} ({$label})");
            }
        }
    }

    private function testKeyword(FlowRunner $runner): void
    {
        $this->info("\n── 5. Keyword globali ──");

        $q = $this->sim($runner, 'menu');
        $this->assertAny(['cosa vuoi fare', 'Prenota', 'menu'], $q, '5.1 Keyword "menu"');
    }

    private function testMatchmakingModules(ModuleRegistry $registry): void
    {
        $this->info("\n── 6. Moduli matchmaking ──");

        $hasAccept = $registry->has('accetta_match');
        $hasRefuse = $registry->has('rifiuta_match');
        $hasSearch = $registry->has('cerca_matchmaking');

        if ($hasAccept) { $this->passed++; $this->line("  ✅ 6.1 Modulo accetta_match registrato"); }
        else { $this->failed++; $this->errors[] = "6.1: accetta_match non trovato"; $this->line("  ❌ 6.1 accetta_match mancante"); }

        if ($hasRefuse) { $this->passed++; $this->line("  ✅ 6.2 Modulo rifiuta_match registrato"); }
        else { $this->failed++; $this->errors[] = "6.2: rifiuta_match non trovato"; $this->line("  ❌ 6.2 rifiuta_match mancante"); }

        if ($hasSearch) { $this->passed++; $this->line("  ✅ 6.3 Modulo cerca_matchmaking registrato"); }
        else { $this->failed++; $this->errors[] = "6.3: cerca_matchmaking non trovato"; $this->line("  ❌ 6.3 cerca_matchmaking mancante"); }

        // Verifica che il flusso risposta esista
        $responseNode = \App\Models\FlowNode::where('entry_trigger', 'scheduler:matchmaking_response')->first();
        if ($responseNode) { $this->passed++; $this->line("  ✅ 6.4 Flusso risposta matchmaking presente"); }
        else { $this->failed++; $this->errors[] = "6.4: nodo scheduler:matchmaking_response non trovato"; $this->line("  ❌ 6.4 Flusso risposta mancante"); }
    }
}
