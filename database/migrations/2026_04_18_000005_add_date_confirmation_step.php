<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Aggiunge un nodo di conferma dopo il parsing della data/ora:
 *
 *   parse_data (ok) → "Cerco uno slot per {friendly}?" [Sì / No, cambia]
 *     ├ Sì → scegli durata
 *     └ No → torna a "Quando vuoi giocare?"
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            $parseWhen   = DB::table('flow_nodes')->where('label', 'Parsa data/ora')->value('id');
            $askDuration = DB::table('flow_nodes')->where('label', 'Scegli durata')->value('id');
            $askWhen     = DB::table('flow_nodes')->where('label', 'Chiedi quando')->value('id');
            $waitWhen    = DB::table('flow_nodes')->where('label', 'Attendi data')->value('id');

            if (!$parseWhen || !$askDuration) return;

            // Crea nodo conferma
            $confirmId = DB::table('flow_nodes')->insertGetId([
                'module_key' => 'invia_bottoni',
                'label'      => 'Conferma data/ora',
                'config'     => json_encode([
                    'text'    => 'Cerco uno slot per {data.requested_friendly}?',
                    'buttons' => [
                        ['label' => 'Sì, cerca'],
                        ['label' => 'No, cambia'],
                    ],
                ]),
                'position'   => json_encode(['x' => 250, 'y' => 860]),
                'is_entry'   => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Rewire: parse_when (ok) → conferma (era → askDuration)
            DB::table('flow_edges')
                ->where('from_node_id', $parseWhen)
                ->where('from_port', 'ok')
                ->where('to_node_id', $askDuration)
                ->delete();

            // parse ok → conferma
            DB::table('flow_edges')->insert([
                ['from_node_id' => $parseWhen, 'from_port' => 'ok', 'to_node_id' => $confirmId, 'to_port' => 'in', 'created_at' => now(), 'updated_at' => now()],
                // Sì → durata
                ['from_node_id' => $confirmId, 'from_port' => 'btn_0', 'to_node_id' => $askDuration, 'to_port' => 'in', 'created_at' => now(), 'updated_at' => now()],
                // No → torna a chiedi quando (o attendi data se askWhen non trovato)
                ['from_node_id' => $confirmId, 'from_port' => 'btn_1', 'to_node_id' => $waitWhen ?? $askWhen ?? $askDuration, 'to_port' => 'in', 'created_at' => now(), 'updated_at' => now()],
                // Fallback → ripeti
                ['from_node_id' => $confirmId, 'from_port' => 'fallback', 'to_node_id' => $confirmId, 'to_port' => 'in', 'created_at' => now(), 'updated_at' => now()],
            ]);
        });
    }

    public function down(): void
    {
        DB::table('flow_nodes')->where('label', 'Conferma data/ora')->delete();
    }
};
