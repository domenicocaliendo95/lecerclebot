<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Aggiunge keyword trigger per saluti comuni (ciao, buongiorno, ecc.)
 * che riportano al trigger principale. Così se un utente scrive "ciao"
 * con il cursore su un nodo vecchio, viene reindirizzato al menu.
 */
return new class extends Migration
{
    public function up(): void
    {
        $mainTrigger = DB::table('flow_nodes')
            ->where('entry_trigger', 'first_message')
            ->where('is_entry', true)
            ->value('id');

        if (!$mainTrigger) return;

        // Trova il nodo successivo al trigger (utente_registrato?)
        $nextEdge = DB::table('flow_edges')
            ->where('from_node_id', $mainTrigger)
            ->first();

        $targetId = $nextEdge?->to_node_id ?? $mainTrigger;

        // Crea keyword triggers per i saluti più comuni
        // Puntano tutti allo stesso nodo del trigger principale
        $greetings = ['ciao', 'buongiorno', 'salve', 'buonasera', 'hey', 'ehi', 'start', 'inizio'];

        foreach ($greetings as $word) {
            // Controlla che non esista già
            $exists = DB::table('flow_nodes')
                ->where('entry_trigger', "keyword:{$word}")
                ->exists();

            if ($exists) continue;

            $nodeId = DB::table('flow_nodes')->insertGetId([
                'module_key'    => 'trigger_keyword',
                'label'         => null,
                'config'        => json_encode(['keyword' => $word]),
                'position'      => json_encode(['x' => 0, 'y' => 0]),
                'is_entry'      => true,
                'entry_trigger' => "keyword:{$word}",
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);

            // Collega allo stesso nodo del trigger principale
            DB::table('flow_edges')->insert([
                'from_node_id' => $nodeId,
                'from_port'    => 'out',
                'to_node_id'   => $targetId,
                'to_port'      => 'in',
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('flow_nodes')
            ->where('module_key', 'trigger_keyword')
            ->whereIn('entry_trigger', [
                'keyword:ciao', 'keyword:buongiorno', 'keyword:salve',
                'keyword:buonasera', 'keyword:hey', 'keyword:ehi',
                'keyword:start', 'keyword:inizio',
            ])
            ->delete();
    }
};
