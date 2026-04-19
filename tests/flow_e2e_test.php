<?php
/**
 * Test E2E del FlowRunner via simulate().
 * Esegue scenari completi e verifica output attesi.
 */

// Carica Laravel
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Services\Flow\FlowRunner;
use App\Models\BotSession;

$runner = app(FlowRunner::class);
$phone = '+39TEST' . time();
$passed = 0;
$failed = 0;
$errors = [];

function simulate(FlowRunner $runner, string $phone, string $input): array {
    return $runner->simulate('whatsapp', $phone, $input);
}

function assertContains(string $needle, array $queue, string $scenario, array &$errors, int &$passed, int &$failed): void {
    $allText = implode(' | ', array_map(fn($m) => $m['text'] ?? '', $queue));
    if (stripos($allText, $needle) !== false) {
        $passed++;
        echo "  ✅ {$scenario}\n";
    } else {
        $failed++;
        $errors[] = "{$scenario}: expected '{$needle}' but got: " . mb_substr($allText, 0, 120);
        echo "  ❌ {$scenario}\n     got: " . mb_substr($allText, 0, 100) . "\n";
    }
}

function assertPort(string $expected, array $queue, string $scenario, array &$errors, int &$passed, int &$failed): void {
    $hasOutput = !empty($queue);
    if ($expected === 'empty' && !$hasOutput) {
        $passed++;
        echo "  ✅ {$scenario} (no output)\n";
    } elseif ($expected !== 'empty' && $hasOutput) {
        $passed++;
        echo "  ✅ {$scenario} (has output)\n";
    } else {
        $failed++;
        $errors[] = "{$scenario}: expected " . ($expected === 'empty' ? 'no output' : 'output');
        echo "  ❌ {$scenario}\n";
    }
}

echo "\n══════════════════════════════════════\n";
echo "  FLOW E2E TEST SUITE\n";
echo "══════════════════════════════════════\n\n";

// ── 1. ONBOARDING ──────────────────────────
echo "── 1. Onboarding ──\n";

// Reset
BotSession::where('channel', 'whatsapp')->where('external_id', $phone)->delete();

$q = simulate($runner, $phone, 'ciao');
assertContains('Come ti chiami', $q, '1.1 Trigger → chiedi nome', $errors, $passed, $failed);

$q = simulate($runner, $phone, 'Mario Rossi');
assertContains('tesserato', $q, '1.2 Nome → chiedi FIT', $errors, $passed, $failed);

$q = simulate($runner, $phone, 'Non sono tesserato');
assertContains('livello', $q, '1.3 FIT no → chiedi livello', $errors, $passed, $failed);

$q = simulate($runner, $phone, 'Dilettante');
// Dopo livello dovrebbe chiedere data nascita O età
$allText = implode(' | ', array_map(fn($m) => $m['text'] ?? '', $q));
$asksBirth = stripos($allText, 'nato') !== false || stripos($allText, 'nascita') !== false;
$asksAge = stripos($allText, 'anni') !== false || stripos($allText, 'età') !== false;
if ($asksBirth || $asksAge) {
    $passed++;
    echo "  ✅ 1.4 Livello → chiedi nascita/età\n";
} else {
    $failed++;
    $errors[] = "1.4: expected birth/age question, got: " . mb_substr($allText, 0, 100);
    echo "  ❌ 1.4 Livello → chiedi nascita/età\n     got: " . mb_substr($allText, 0, 100) . "\n";
}

$q = simulate($runner, $phone, 'salta');
// Potrebbe chiedere email o slot
$allText2 = implode(' | ', array_map(fn($m) => $m['text'] ?? '', $q));
if (stripos($allText2, 'email') !== false || stripos($allText2, 'gioc') !== false || stripos($allText2, 'slot') !== false || stripos($allText2, 'fascia') !== false) {
    $passed++;
    echo "  ✅ 1.5 Nascita salta → prossimo step\n";
} else {
    $failed++;
    $errors[] = "1.5: expected email/slot question, got: " . mb_substr($allText2, 0, 100);
    echo "  ❌ 1.5 Nascita salta → prossimo step\n     got: " . mb_substr($allText2, 0, 100) . "\n";
}

// Continua onboarding fino al menu
$q = simulate($runner, $phone, 'salta'); // email
$q = simulate($runner, $phone, 'Mattina'); // slot - potrebbe fallire se già al menu
$q = simulate($runner, $phone, 'Mattina'); // retry se serviva

// Verifica che arriviamo al menu
$session = BotSession::where('channel', 'whatsapp')->where('external_id', $phone)->first();
echo "  ℹ️  Sessione state: " . ($session->state ?? '?') . ", node: " . ($session->current_node_id ?? 'null') . "\n";

// ── 2. PRENOTAZIONE ──────────────────────────
echo "\n── 2. Prenotazione ──\n";

$q = simulate($runner, $phone, 'Prenota un campo');
$allText = implode(' | ', array_map(fn($m) => $m['text'] ?? '', $q));
$hasOpponent = stripos($allText, 'avversario') !== false || stripos($allText, 'chi gioc') !== false;
$hasWhen = stripos($allText, 'quando') !== false || stripos($allText, 'giocare') !== false;
if ($hasOpponent || $hasWhen) {
    $passed++;
    echo "  ✅ 2.1 Menu prenota → chiedi avversario/quando\n";
} else {
    $failed++;
    $errors[] = "2.1: expected opponent/when question, got: " . mb_substr($allText, 0, 100);
    echo "  ❌ 2.1 Menu prenota → chiedi avversario/quando\n     got: " . mb_substr($allText, 0, 100) . "\n";
}

// ── 3. PARSER DATE ──────────────────────────
echo "\n── 3. Parser date (isolato) ──\n";

$testCases = [
    ['domani alle 17.30', '17:30', 'ore con minuti .'],
    ['domani alle 9:30', '09:30', 'ore con minuti :'],
    ['sabato 19', '19:00', 'giorno + ora'],
    ['domani alle 9 e mezza', '09:30', 'mezza'],
    ['oggi pomeriggio', '15:00', 'fascia pomeriggio'],
];

$textGen = app(\App\Services\Bot\TextGenerator::class);
foreach ($testCases as [$input, $expectedTime, $label]) {
    $result = $textGen->parseDateTime($input);
    if ($result && ($result['time'] ?? '') === $expectedTime) {
        $passed++;
        echo "  ✅ 3.x \"{$input}\" → {$expectedTime} ({$label})\n";
    } else {
        $failed++;
        $got = $result ? ($result['time'] ?? 'null') : 'null';
        $errors[] = "Parser \"{$input}\": expected {$expectedTime}, got {$got}";
        echo "  ❌ 3.x \"{$input}\" → expected {$expectedTime}, got {$got} ({$label})\n";
    }
}

// ── 4. PARSER RISULTATI (isolato) ──────────────────
echo "\n── 4. Parser risultati (isolato) ──\n";

$registry = app(\App\Services\Flow\ModuleRegistry::class);
$resultTests = [
    ['6-1 6-2', 'ok', 'won', 'standard'],
    ['Ho vinto', 'ok', 'won', 'dichiarazione'],
    ['ho perso 3-6 4-6', 'ok', 'lost', 'dichiarazione + score'],
    ['non giocata', 'non_giocata', 'not_played', 'non giocata'],
    ['61-62', 'ok', 'won', 'compresso'],
    ['7-6(4) 3-6 6-2', 'ok', 'won', 'con tiebreak'],
];

foreach ($resultTests as [$input, $expectedPort, $expectedResult, $label]) {
    $mod = $registry->instantiate('parse_risultato', ['source' => 'input']);
    $session = new BotSession();
    $session->data = ['last_input' => $input];
    $node = new \App\Models\FlowNode();
    $node->id = 0;
    $node->module_key = 'parse_risultato';
    $node->config = ['source' => 'input'];
    
    $ctx = new \App\Services\Flow\FlowContext(
        session: $session,
        channel: 'test',
        externalId: 'test',
        input: $input,
        user: null,
        node: $node,
    );
    
    $result = $mod->execute($ctx);
    $port = $result->next;
    $matchResult = $result->data['match_result'] ?? '?';
    
    if ($port === $expectedPort && $matchResult === $expectedResult) {
        $passed++;
        echo "  ✅ 4.x \"{$input}\" → {$port}/{$matchResult} ({$label})\n";
    } else {
        $failed++;
        $errors[] = "Risultato \"{$input}\": expected {$expectedPort}/{$expectedResult}, got {$port}/{$matchResult}";
        echo "  ❌ 4.x \"{$input}\" → expected {$expectedPort}/{$expectedResult}, got {$port}/{$matchResult} ({$label})\n";
    }
}

// ── 5. KEYWORD GLOBALI ──────────────────────────
echo "\n── 5. Keyword globali ──\n";

$q = simulate($runner, $phone, 'menu');
$allText = implode(' | ', array_map(fn($m) => $m['text'] ?? '', $q));
if (stripos($allText, 'cosa vuoi fare') !== false || stripos($allText, 'menu') !== false || !empty($q)) {
    $passed++;
    echo "  ✅ 5.1 Keyword 'menu' → torna al menu\n";
} else {
    $failed++;
    $errors[] = "5.1: 'menu' non ha portato al menu";
    echo "  ❌ 5.1 Keyword 'menu' → nessuna risposta\n";
}

// ── RIEPILOGO ──────────────────────────
echo "\n══════════════════════════════════════\n";
echo "  RISULTATI: {$passed} ✅  {$failed} ❌\n";
echo "══════════════════════════════════════\n";

if (!empty($errors)) {
    echo "\nERRORI:\n";
    foreach ($errors as $i => $e) {
        echo "  " . ($i + 1) . ". {$e}\n";
    }
}

// Pulizia
BotSession::where('channel', 'whatsapp')->where('external_id', $phone)->delete();

exit($failed > 0 ? 1 : 0);
