<?php

namespace App\Console\Commands;

use App\Models\BotSession;
use App\Services\Flow\FlowRunner;
use Illuminate\Console\Command;

/**
 * Simula una conversazione sul nuovo FlowRunner senza spedire messaggi
 * WhatsApp reali. Utile per testare i grafi prima di cablarli al webhook.
 *
 * Esempio:
 *   php artisan flow:simulate +393331112233 "ciao"
 *   php artisan flow:simulate +393331112233 "Mario Rossi"
 *   php artisan flow:simulate +393331112233 "Sì, tesserato"
 *
 * Il comando riusa la stessa BotSession del numero: ripetere i comandi in
 * sequenza simula una vera conversazione passo-passo.
 *
 * Opzioni:
 *   --reset   azzera la sessione prima di partire
 *   --state   stampa lo stato sessione in forma compatta alla fine
 */
class FlowSimulate extends Command
{
    protected $signature = 'flow:simulate
                            {phone : Numero di telefono della sessione (anche finto)}
                            {message : Messaggio utente da iniettare}
                            {--reset : Azzera la sessione prima di eseguire}
                            {--state : Stampa lo stato sessione dopo l\'esecuzione}';

    protected $description = 'Esegue il FlowRunner su un messaggio simulato e stampa i messaggi in uscita (senza toccare WhatsApp).';

    public function handle(FlowRunner $runner): int
    {
        $phone   = (string) $this->argument('phone');
        $message = (string) $this->argument('message');

        if ($this->option('reset')) {
            BotSession::where('phone', $phone)->update([
                'current_node_id' => null,
                'data'            => null,
                'state'           => 'NEW',
            ]);
            $this->line("↺ Sessione {$phone} resettata");
        }

        $this->line("→ {$phone}: " . $this->quote($message));
        $queue = $runner->simulate($phone, $message);

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
            $session = BotSession::where('phone', $phone)->first();
            if ($session) {
                $this->line('');
                $this->line('sessione:');
                $this->line('  current_node_id: ' . ($session->current_node_id ?? 'null'));
                $data = $session->data ?? [];
                unset($data['history']); // troppo rumore
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
