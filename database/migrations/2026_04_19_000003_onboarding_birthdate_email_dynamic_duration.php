<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 1. Onboarding: sostituisce "Chiedi età" con "Chiedi data di nascita" (con salta)
 *    + aggiunge "Chiedi email" (con salta) prima della fascia oraria
 * 2. Prenotazione: sostituisce i 4 nodi durata hardcoded (1h/1h30/2h + 3 salva)
 *    con un singolo nodo chiedi_durata che legge da PricingRule
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            $this->updateOnboarding();
            $this->updateDuration();
        });
    }

    private function updateOnboarding(): void
    {
        // 1. Sostituisci "Chiedi età" con "Chiedi data di nascita" (saltabile)
        $askEta = DB::table('flow_nodes')->where('label', 'Chiedi età')->first();
        if ($askEta) {
            DB::table('flow_nodes')->where('id', $askEta->id)->update([
                'label'  => 'Chiedi data di nascita',
                'config' => json_encode([
                    'question'      => 'Quando sei nato? Scrivi la data (gg/mm/aaaa) oppure scrivi "salta".',
                    'save_to'       => 'profile.birthdate',
                    'validator'     => 'any', // accetta sia data che "salta"
                    'retry_message' => 'Formato accettato: gg/mm/aaaa (es. 15/03/1990). Oppure scrivi "salta".',
                ]),
            ]);

            // Aggiungo un nodo di elaborazione dopo: se l'input è "salta" → skip,
            // se è una data valida → salva. Lo faccio come nodo separato per pulizia.
            // Cerco il nodo successivo (Chiedi fascia / ask_slot)
            $nextEdge = DB::table('flow_edges')
                ->where('from_node_id', $askEta->id)
                ->where('from_port', 'ok')
                ->first();

            if ($nextEdge) {
                // Inserisco un nodo che gestisce "salta" vs data valida
                $processNode = DB::table('flow_nodes')->insertGetId([
                    'module_key' => 'salva_in_sessione',
                    'label'      => 'Processa nascita',
                    'config'     => json_encode([
                        'assignments' => [], // placeholder: il chiedi_campo salva già in profile.birthdate
                    ]),
                    'position'   => json_encode(['x' => -250, 'y' => 990]),
                    'is_entry'   => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // Chiedi email (saltabile)
                $askEmail = DB::table('flow_nodes')->insertGetId([
                    'module_key' => 'chiedi_campo',
                    'label'      => 'Chiedi email',
                    'config'     => json_encode([
                        'question'      => "La tua email? Ci serve per comunicazioni importanti.\nScrivi \"salta\" se preferisci non inserirla.",
                        'save_to'       => 'profile.email',
                        'validator'     => 'any', // accetta email o "salta"
                        'retry_message' => 'Inserisci un indirizzo email valido oppure scrivi "salta".',
                    ]),
                    'position'   => json_encode(['x' => -250, 'y' => 1030]),
                    'is_entry'   => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // Rewire: birthdate ok → processNode → askEmail → vecchio next (fascia oraria)
                DB::table('flow_edges')->where('id', $nextEdge->id)->update([
                    'from_node_id' => $askEmail,
                    'from_port'    => 'ok',
                ]);

                DB::table('flow_edges')->insert([
                    ['from_node_id' => $askEta->id, 'from_port' => 'ok', 'to_node_id' => $processNode, 'to_port' => 'in', 'created_at' => now(), 'updated_at' => now()],
                    ['from_node_id' => $processNode, 'from_port' => 'out', 'to_node_id' => $askEmail, 'to_port' => 'in', 'created_at' => now(), 'updated_at' => now()],
                ]);

                // Fix: rimuovo il vecchio edge ok che abbiamo spostato
                // (l'update sopra lo ha già gestito)
            }
        }
    }

    private function updateDuration(): void
    {
        // Trova i nodi durata hardcoded
        $askDuration = DB::table('flow_nodes')->where('label', 'Scegli durata')->value('id');
        $dur60  = DB::table('flow_nodes')->where('label', '60 min')->value('id');
        $dur90  = DB::table('flow_nodes')->where('label', '90 min')->value('id');
        $dur120 = DB::table('flow_nodes')->where('label', '120 min')->value('id');

        if (!$askDuration) return;

        // Trova dove puntano i nodi durata (dovrebbe essere check_cal)
        $targetId = null;
        foreach ([$dur60, $dur90, $dur120] as $durId) {
            if (!$durId) continue;
            $edge = DB::table('flow_edges')->where('from_node_id', $durId)->first();
            if ($edge) { $targetId = $edge->to_node_id; break; }
        }

        // Trova chi punta a askDuration (dovrebbe essere parse_data ok o conferma data)
        $incoming = DB::table('flow_edges')->where('to_node_id', $askDuration)->get();

        // Crea il nuovo nodo chiedi_durata
        $newNode = DB::table('flow_nodes')->insertGetId([
            'module_key' => 'chiedi_durata',
            'label'      => 'Scegli durata',
            'config'     => json_encode([
                'text' => 'Quanto vuoi giocare?',
            ]),
            'position'   => json_encode(['x' => 250, 'y' => 890]),
            'is_entry'   => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Rewire incoming → nuovo nodo
        foreach ($incoming as $edge) {
            DB::table('flow_edges')->where('id', $edge->id)->update([
                'to_node_id' => $newNode,
            ]);
        }

        // Nuovo nodo ok → target (check_cal)
        if ($targetId) {
            DB::table('flow_edges')->insert([
                'from_node_id' => $newNode,
                'from_port'    => 'ok',
                'to_node_id'   => $targetId,
                'to_port'      => 'in',
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
        }

        // Elimina i vecchi nodi durata (cascade sugli edge)
        DB::table('flow_nodes')->whereIn('id', array_filter([$askDuration, $dur60, $dur90, $dur120]))->delete();
    }

    public function down(): void
    {
        // Non reversibile automaticamente
    }
};
