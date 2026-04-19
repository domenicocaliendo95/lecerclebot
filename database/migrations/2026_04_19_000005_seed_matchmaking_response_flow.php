<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Crea il flusso di risposta matchmaking (lato avversario).
 *
 * Quando CercaMatchmakingModule trova un avversario, gli manda
 * [Accetta/Rifiuta] e setta il cursore su questo flusso.
 * L'avversario risponde → accetta_match o rifiuta_match → notifica.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            // Entry: bottoni [Accetta / Rifiuta]
            $entry = $this->node('invia_bottoni', [
                'text'    => '{data.matchmaking_challenger_name} ti sfida per {data.matchmaking_slot}! 🎾 Accetti?',
                'buttons' => [
                    ['label' => 'Accetta'],
                    ['label' => 'Rifiuta'],
                ],
            ], ['x' => 1400, 'y' => 0], 'Risposta matchmaking',
                isEntry: true, entryTrigger: 'scheduler:matchmaking_response');

            // Accetta
            $accept = $this->node('accetta_match', [], ['x' => 1300, 'y' => 130], 'Accetta sfida');
            $acceptMsg = $this->node('invia_testo', [
                'text' => 'Match confermato! 🎾✅ L\'evento è stato aggiunto al calendario. Buona partita!',
            ], ['x' => 1300, 'y' => 260], 'Match confermato');

            // Rifiuta
            $refuse = $this->node('rifiuta_match', [], ['x' => 1500, 'y' => 130], 'Rifiuta sfida');
            $refuseMsg = $this->node('invia_testo', [
                'text' => 'OK, sfida rifiutata. Lo sfidante verrà avvisato.',
            ], ['x' => 1500, 'y' => 260], 'Sfida rifiutata');

            // Fine
            $end = $this->node('fine_flusso', [], ['x' => 1400, 'y' => 380], 'Fine matchmaking resp.');

            // Edges
            $this->edge($entry, 'btn_0', $accept);     // Accetta
            $this->edge($entry, 'btn_1', $refuse);     // Rifiuta
            $this->edge($entry, 'fallback', $entry);   // Non capito → ripeti

            $this->edge($accept, 'ok', $acceptMsg);
            $this->edge($accept, 'errore', $acceptMsg);
            $this->edge($refuse, 'ok', $refuseMsg);
            $this->edge($refuse, 'errore', $refuseMsg);

            $this->edge($acceptMsg, 'out', $end);
            $this->edge($refuseMsg, 'out', $end);
        });
    }

    public function down(): void
    {
        DB::table('flow_nodes')->where('entry_trigger', 'scheduler:matchmaking_response')->delete();
        DB::table('flow_nodes')->whereIn('label', [
            'Accetta sfida', 'Match confermato', 'Rifiuta sfida',
            'Sfida rifiutata', 'Fine matchmaking resp.',
        ])->delete();
    }

    private function node(
        string $moduleKey, array $config, array $position,
        ?string $label = null, bool $isEntry = false, ?string $entryTrigger = null,
    ): int {
        return DB::table('flow_nodes')->insertGetId([
            'module_key'    => $moduleKey,
            'label'         => $label,
            'config'        => json_encode($config),
            'position'      => json_encode($position),
            'is_entry'      => $isEntry,
            'entry_trigger' => $entryTrigger,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
    }

    private function edge(int $from, string $port, int $to): void
    {
        DB::table('flow_edges')->insert([
            'from_node_id' => $from, 'from_port' => $port,
            'to_node_id'   => $to,   'to_port'   => 'in',
            'created_at'   => now(), 'updated_at' => now(),
        ]);
    }
};
