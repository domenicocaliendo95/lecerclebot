<?php

namespace App\Console\Commands;

use App\Models\BotSession;
use App\Services\Flow\FlowRunner;
use Illuminate\Console\Command;

/**
 * Simula una conversazione sul FlowRunner senza spedire messaggi reali.
 *
 * Esempi:
 *   php artisan flow:simulate +393331112233 "ciao"
 *   php artisan flow:simulate +393331112233 "Mario" --state
 *   php artisan flow:simulate web_test_01 "ciao" --channel=webchat --reset
 *
 * Il comando riusa la stessa BotSession: ripetere i comandi in sequenza
 * simula una conversazione passo-passo. Il parametro `identifier` è
 * canale-specifico: telefono per WhatsApp, session id (UUID) per webchat.
 */
class FlowSimulate extends Command
{
    protected $signature = 'flow:simulate
                            {identifier : Identificatore della sessione (telefono per WA, UUID per webchat)}
                            {message : Messaggio utente da iniettare}
                            {--channel=whatsapp : Canale della sessione}
                            {--reset : Azzera la sessione prima di eseguire}
                            {--state : Stampa lo stato sessione dopo l\'esecuzione}';

    protected $description = 'Esegue il FlowRunner su un messaggio simulato e stampa i messaggi in uscita (senza toccare canali reali).';

    public function handle(FlowRunner $runner): int
    {
        $identifier = (string) $this->argument('identifier');
        $message    = (string) $this->argument('message');
        $channel    = (string) $this->option('channel');

        if ($this->option('reset')) {
            BotSession::where('channel', $channel)->where('external_id', $identifier)->update([
                'current_node_id' => null,
                'data'            => null,
                'state'           => 'NEW',
            ]);
            $this->line("↺ Sessione {$channel}:{$identifier} resettata");
        }

        $this->line("→ [{$channel}] {$identifier}: " . $this->quote($message));
        $queue = $runner->simulate($channel, $identifier, $message);

        if (empty($queue)) {
            $this->warn('   (nessun messaggio in uscita — niente trigger matchato?)');
        }

        foreach ($queue as $msg) {
            $type = $msg['type'] ?? 'text';
            $text = $msg['text'] ?? '';
            $this->line("← [{$type}] " . $this->quote($text));
            if ($type === 'buttons' && !empty($msg['buttons'])) {
                foreach ((array) $msg['buttons'] as $b) {
                    $this->line('      · ' . $b);
                }
            }
        }

        if ($this->option('state')) {
            $session = BotSession::where('channel', $channel)->where('external_id', $identifier)->first();
            if ($session) {
                $this->line('');
                $this->line('sessione:');
                $this->line('  current_node_id: ' . ($session->current_node_id ?? 'null'));
                $data = $session->data ?? [];
                unset($data['history']);
                $this->line('  data: ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            }
        }

        return self::SUCCESS;
    }

    private function quote(string $s): string
    {
        return '"' . str_replace("\n", ' ', $s) . '"';
    }
}
