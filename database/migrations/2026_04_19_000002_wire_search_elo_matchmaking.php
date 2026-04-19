<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 1. Sostituisce "Nome avversario" (chiedi_campo) con "cerca_utente" (fuzzy DB)
 * 2. Inserisce "aggiorna_elo" nel flusso post-partita dopo il risultato
 * 3. Crea il flusso matchmaking: cerca per ELO → invito → attesa → conferma
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            $this->upgradeOpponentSearch();
            $this->wireEloInPostMatch();
            $this->createMatchmakingFlow();
        });
    }

    /**
     * 1. Sostituisce il nodo "Nome avversario" (chiedi_campo) con cerca_utente.
     */
    private function upgradeOpponentSearch(): void
    {
        $old = DB::table('flow_nodes')->where('label', 'Nome avversario')->first();
        if (!$old) return;

        DB::table('flow_nodes')->where('id', $old->id)->update([
            'module_key' => 'cerca_utente',
            'label'      => 'Cerca avversario',
            'config'     => json_encode([
                'source'      => 'opponent_name',
                'max_results' => 3,
            ]),
        ]);

        // Il vecchio nodo aveva solo porta "ok" → askWhen.
        // Il nuovo ha "confermato", "non_trovato", "salta".
        // Rewire: rinomina la porta "ok" → "confermato" sull'edge esistente.
        DB::table('flow_edges')
            ->where('from_node_id', $old->id)
            ->where('from_port', 'ok')
            ->update(['from_port' => 'confermato']);

        // Aggiungi edge per "non_trovato" → stessa destinazione (continua al booking)
        $confEdge = DB::table('flow_edges')
            ->where('from_node_id', $old->id)
            ->where('from_port', 'confermato')
            ->first();

        if ($confEdge) {
            // non_trovato → stessa destinazione (nome libero, niente ELO)
            DB::table('flow_edges')->insert([
                'from_node_id' => $old->id,
                'from_port'    => 'non_trovato',
                'to_node_id'   => $confEdge->to_node_id,
                'to_port'      => 'in',
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
        }

        // Ma ora il modulo è cerca_utente, non chiedi_campo: non fa più
        // l'ask della domanda. Serve un chiedi_campo PRIMA.
        // Verifichiamo: l'edge in ingresso punta dal bottone "Ho un avversario".
        // L'utente clicca "Ho un avversario" → OLD era chiedi_campo (chiedeva nome + validava).
        // Ora serve: chiedi_campo (solo ask nome) → cerca_utente (cerca DB).
        // Ma chiedi_campo già esiste con "Come si chiama?" — ah no, l'abbiamo SOSTITUITO.
        // Ricreiamo il chiedi_campo prima di cerca_utente.

        $askName = DB::table('flow_nodes')->insertGetId([
            'module_key' => 'chiedi_campo',
            'label'      => 'Chiedi nome avversario',
            'config'     => json_encode([
                'question'      => 'Come si chiama il tuo avversario? Scrivi nome e cognome.',
                'save_to'       => 'opponent_name',
                'validator'     => 'name',
                'retry_message' => 'Scrivi il nome per esteso (es. Mario Rossi)',
            ]),
            'position'   => json_encode(['x' => 0, 'y' => 550]),
            'is_entry'   => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Rewire: chi puntava a "Nome avversario" (ora cerca_utente) → punta a askName
        $incomingEdges = DB::table('flow_edges')
            ->where('to_node_id', $old->id)
            ->get();

        foreach ($incomingEdges as $edge) {
            DB::table('flow_edges')->where('id', $edge->id)->update([
                'to_node_id' => $askName,
            ]);
        }

        // askName ok → cerca_utente
        DB::table('flow_edges')->insert([
            'from_node_id' => $askName,
            'from_port'    => 'ok',
            'to_node_id'   => $old->id,
            'to_port'      => 'in',
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }

    /**
     * 2. Inserisce aggiorna_elo nel flusso post-partita, dopo "Risultato OK".
     */
    private function wireEloInPostMatch(): void
    {
        $resultOk = DB::table('flow_nodes')
            ->where('label', 'Risultato OK')
            ->where('module_key', 'invia_testo')
            ->value('id');

        $askRating = DB::table('flow_nodes')
            ->where('label', 'Chiedi feedback')
            ->where('module_key', 'invia_bottoni')
            ->value('id');

        if (!$resultOk || !$askRating) return;

        // Crea nodo ELO
        $eloNode = DB::table('flow_nodes')->insertGetId([
            'module_key' => 'aggiorna_elo',
            'label'      => 'Aggiorna ELO',
            'config'     => json_encode([]),
            'position'   => json_encode(['x' => 700, 'y' => 320]),
            'is_entry'   => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Rewire: resultOk → eloNode → askRating (era resultOk → askRating)
        DB::table('flow_edges')
            ->where('from_node_id', $resultOk)
            ->where('to_node_id', $askRating)
            ->delete();

        DB::table('flow_edges')->insert([
            ['from_node_id' => $resultOk, 'from_port' => 'out', 'to_node_id' => $eloNode, 'to_port' => 'in', 'created_at' => now(), 'updated_at' => now()],
            ['from_node_id' => $eloNode, 'from_port' => 'aggiornato', 'to_node_id' => $askRating, 'to_port' => 'in', 'created_at' => now(), 'updated_at' => now()],
            ['from_node_id' => $eloNode, 'from_port' => 'skip', 'to_node_id' => $askRating, 'to_port' => 'in', 'created_at' => now(), 'updated_at' => now()],
            ['from_node_id' => $eloNode, 'from_port' => 'errore', 'to_node_id' => $askRating, 'to_port' => 'in', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    /**
     * 3. Crea il flusso matchmaking (separato dal principale).
     *    L'entry è raggiunto dal menu "Trovami avversario" (set_type_mm).
     */
    private function createMatchmakingFlow(): void
    {
        // Il nodo set_type_mm già esiste e punta a ask_when.
        // Il matchmaking segue lo stesso percorso (quando → durata → calendario)
        // ma poi cerca per ELO invece di chiedere pagamento.

        // Dopo verifica_calendario libero, per matchmaking:
        // search matchmaking → trovato/nessuno
        $searchNode = DB::table('flow_nodes')->insertGetId([
            'module_key' => 'cerca_matchmaking',
            'label'      => 'Cerca per ELO',
            'config'     => json_encode([]),
            'position'   => json_encode(['x' => 500, 'y' => 1170]),
            'is_entry'   => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $foundMsg = DB::table('flow_nodes')->insertGetId([
            'module_key' => 'invia_testo',
            'label'      => 'Match trovato!',
            'config'     => json_encode([
                'text' => "Ho trovato un avversario: {data.matchmaking_opponent}! 🎾 Gli ho mandato un invito. Ti avviso quando risponde.",
            ]),
            'position'   => json_encode(['x' => 400, 'y' => 1300]),
            'is_entry'   => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $notFoundMsg = DB::table('flow_nodes')->insertGetId([
            'module_key' => 'invia_testo',
            'label'      => 'Nessun match',
            'config'     => json_encode([
                'text' => 'Mi dispiace, non ho trovato avversari disponibili con un livello simile al tuo. Riprova più tardi!',
            ]),
            'position'   => json_encode(['x' => 650, 'y' => 1300]),
            'is_entry'   => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $endMatch = DB::table('flow_nodes')->insertGetId([
            'module_key' => 'fine_flusso',
            'label'      => 'Fine matchmaking',
            'config'     => json_encode([]),
            'position'   => json_encode(['x' => 500, 'y' => 1420]),
            'is_entry'   => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('flow_edges')->insert([
            ['from_node_id' => $searchNode, 'from_port' => 'trovato', 'to_node_id' => $foundMsg, 'to_port' => 'in', 'created_at' => now(), 'updated_at' => now()],
            ['from_node_id' => $searchNode, 'from_port' => 'nessuno', 'to_node_id' => $notFoundMsg, 'to_port' => 'in', 'created_at' => now(), 'updated_at' => now()],
            ['from_node_id' => $searchNode, 'from_port' => 'errore', 'to_node_id' => $notFoundMsg, 'to_port' => 'in', 'created_at' => now(), 'updated_at' => now()],
            ['from_node_id' => $foundMsg, 'from_port' => 'out', 'to_node_id' => $endMatch, 'to_port' => 'in', 'created_at' => now(), 'updated_at' => now()],
            ['from_node_id' => $notFoundMsg, 'from_port' => 'out', 'to_node_id' => $endMatch, 'to_port' => 'in', 'created_at' => now(), 'updated_at' => now()],
        ]);

        // Rewire: il menu "Trovami avversario" (set_type_mm) punta a askWhen.
        // Dopo il calendario, per booking_type=matchmaking serve un branch
        // che vada a searchNode invece che a askPayment.
        // Per ora, il matchmaking passa dallo stesso percorso fino al calendario,
        // poi bisogna fare un fork. Il fork lo gestiremo con un modulo condizionale
        // basato su booking_type. Lo inseriamo tra slot_libero e askPayment.

        $slotLibero = DB::table('flow_nodes')->where('label', 'Slot libero')->value('id');
        $askPayment = DB::table('flow_nodes')->where('label', 'Scegli pagamento')->value('id');

        if ($slotLibero && $askPayment) {
            // Branch condizionale: se booking_type=matchmaking → search ELO,
            // altrimenti → pagamento (con_avversario, sparapalline)
            $branchNode = DB::table('flow_nodes')->insertGetId([
                'module_key' => 'condizione_campo',
                'label'      => 'Tipo prenotazione?',
                'config'     => json_encode([
                    'campo'  => 'booking_type',
                    'valori' => ['matchmaking', 'con_avversario', 'sparapalline'],
                ]),
                'position'   => json_encode(['x' => 250, 'y' => 1130]),
                'is_entry'   => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Rewire: slot_libero → branch (era → askPayment)
            DB::table('flow_edges')
                ->where('from_node_id', $slotLibero)
                ->where('to_node_id', $askPayment)
                ->delete();

            DB::table('flow_edges')->insert([
                // slot_libero → branch
                ['from_node_id' => $slotLibero, 'from_port' => 'out', 'to_node_id' => $branchNode, 'to_port' => 'in', 'created_at' => now(), 'updated_at' => now()],
                // matchmaking → search ELO
                ['from_node_id' => $branchNode, 'from_port' => 'matchmaking', 'to_node_id' => $searchNode, 'to_port' => 'in', 'created_at' => now(), 'updated_at' => now()],
                // con_avversario → pagamento
                ['from_node_id' => $branchNode, 'from_port' => 'con_avversario', 'to_node_id' => $askPayment, 'to_port' => 'in', 'created_at' => now(), 'updated_at' => now()],
                // sparapalline → pagamento
                ['from_node_id' => $branchNode, 'from_port' => 'sparapalline', 'to_node_id' => $askPayment, 'to_port' => 'in', 'created_at' => now(), 'updated_at' => now()],
                // altro → pagamento
                ['from_node_id' => $branchNode, 'from_port' => 'altro', 'to_node_id' => $askPayment, 'to_port' => 'in', 'created_at' => now(), 'updated_at' => now()],
            ]);
        }
    }

    public function down(): void
    {
        DB::table('flow_nodes')->where('label', 'Cerca avversario')->where('module_key', 'cerca_utente')
            ->update(['module_key' => 'chiedi_campo', 'label' => 'Nome avversario']);
        DB::table('flow_nodes')->where('label', 'Aggiorna ELO')->delete();
        DB::table('flow_nodes')->where('label', 'Cerca per ELO')->delete();
        DB::table('flow_nodes')->where('label', 'Match trovato!')->delete();
        DB::table('flow_nodes')->where('label', 'Nessun match')->delete();
        DB::table('flow_nodes')->where('label', 'Fine matchmaking')->delete();
        DB::table('flow_nodes')->where('label', 'Chiedi nome avversario')->delete();
    }
};
