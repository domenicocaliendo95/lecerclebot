<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Unifica risultato + feedback in un singolo flusso post-partita.
 *
 * Rimuove i vecchi flussi scheduler:result_request e scheduler:feedback_request
 * e li sostituisce con un unico scheduler:post_match:
 *
 *   "Com'è andata?" → parse risultato
 *     ├ ok → conferma → "Feedback?" [Scarso/Medio/Ottimo]
 *     │        ↓ salva rating → "Commento?" [Salta] / testo
 *     │        ↓ salva feedback → "Grazie!" → fine
 *     ├ non_giocata → msg → fine
 *     └ non_capito → riprova → loop
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            // Elimina i vecchi flussi separati (cascade sugli edge)
            DB::table('flow_nodes')->where('entry_trigger', 'scheduler:result_request')->delete();
            DB::table('flow_nodes')->where('entry_trigger', 'scheduler:feedback_request')->delete();
            // Pulisci anche i nodi orfani dei vecchi sotto-flussi
            $this->cleanOrphans();

            // Crea il flusso unificato
            $this->createPostMatchFlow();

            // Aggiorna bot_settings
            $entryId = DB::table('flow_nodes')
                ->where('entry_trigger', 'scheduler:post_match')
                ->value('id');

            DB::table('bot_settings')->updateOrInsert(
                ['key' => 'post_match'],
                ['value' => json_encode([
                    'enabled'       => true,
                    'hours_after'   => 1,
                    'flow_node_id'  => $entryId,
                ]), 'updated_at' => now()],
            );
        });
    }

    private function createPostMatchFlow(): void
    {
        // ── 1. Chiedi risultato
        $entry = $this->node('invia_bottoni', [
            'text'    => "Com'è andata la partita di {slot}? 🎾\n\nPuoi scrivere il punteggio (es. 6-3 6-4) oppure:",
            'buttons' => [
                ['label' => 'Ho vinto'],
                ['label' => 'Ho perso'],
                ['label' => 'Non giocata'],
            ],
        ], ['x' => 800, 'y' => 0], 'Post-partita', isEntry: true, entryTrigger: 'scheduler:post_match');

        // ── 2. Parse risultato
        $parse = $this->node('parse_risultato', ['source' => 'input'], ['x' => 800, 'y' => 130], 'Parsa risultato');

        // ── 3a. Risultato ok → conferma
        $confirmResult = $this->node('invia_testo', [
            'text' => 'Risultato registrato ✅',
        ], ['x' => 700, 'y' => 260], 'Risultato OK');

        // ── 3b. Non giocata
        $notPlayed = $this->node('invia_testo', [
            'text' => 'OK, partita segnata come non giocata.',
        ], ['x' => 950, 'y' => 260], 'Non giocata');

        $notPlayedEnd = $this->node('fine_flusso', [], ['x' => 950, 'y' => 380], 'Fine (non giocata)');

        // ── 3c. Non capito → loop
        $retry = $this->node('invia_testo', [
            'text' => "Non ho capito 🤔 Scrivi il punteggio (es. 6-3 6-4) oppure premi un bottone.",
        ], ['x' => 1100, 'y' => 260], 'Riprova risultato');

        // ── 4. Chiedi feedback rating
        $askRating = $this->node('invia_bottoni', [
            'text'    => "Com'è stata l'esperienza al circolo?",
            'buttons' => [
                ['label' => '⭐ Scarso'],
                ['label' => '⭐⭐ Medio'],
                ['label' => '⭐⭐⭐ Ottimo'],
            ],
        ], ['x' => 700, 'y' => 380], 'Chiedi feedback');

        // ── 5. Salva rating
        $saveR1 = $this->node('salva_in_sessione', [
            'assignments' => [['key' => 'feedback_rating', 'value' => 1]],
        ], ['x' => 600, 'y' => 510], 'Rating: 1');

        $saveR3 = $this->node('salva_in_sessione', [
            'assignments' => [['key' => 'feedback_rating', 'value' => 3]],
        ], ['x' => 750, 'y' => 510], 'Rating: 3');

        $saveR5 = $this->node('salva_in_sessione', [
            'assignments' => [['key' => 'feedback_rating', 'value' => 5]],
        ], ['x' => 900, 'y' => 510], 'Rating: 5');

        // ── 6. Chiedi commento (saltabile)
        $askComment = $this->node('invia_bottoni', [
            'text'    => 'Vuoi lasciarci un commento?',
            'buttons' => [['label' => 'Salta']],
        ], ['x' => 750, 'y' => 640], 'Chiedi commento');

        // ── 7. Salva commento da testo libero
        $saveComment = $this->node('salva_in_sessione', [
            'assignments' => [['key' => 'feedback_comment', 'value' => '{data.last_input}']],
        ], ['x' => 650, 'y' => 770], 'Salva commento');

        // ── 8. Salva feedback in DB
        $saveFb = $this->node('salva_feedback', ['type' => 'post_match'], ['x' => 750, 'y' => 880], 'Salva feedback');

        // ── 9. Grazie + fine
        $thanks = $this->node('invia_testo', ['text' => 'Grazie per il feedback! 🙏'], ['x' => 750, 'y' => 990], 'Grazie');
        $end = $this->node('fine_flusso', [], ['x' => 750, 'y' => 1100], 'Fine post-partita');

        // ── Edges
        // Entry → parse (tutti i bottoni + fallback vanno al parser)
        $this->edge($entry, 'btn_0', $parse);
        $this->edge($entry, 'btn_1', $parse);
        $this->edge($entry, 'btn_2', $parse);
        $this->edge($entry, 'fallback', $parse);

        $this->edge($parse, 'ok', $confirmResult);
        $this->edge($parse, 'non_giocata', $notPlayed);
        $this->edge($parse, 'non_capito', $retry);
        $this->edge($retry, 'out', $entry); // loop

        $this->edge($notPlayed, 'out', $notPlayedEnd);

        // Dopo risultato → feedback
        $this->edge($confirmResult, 'out', $askRating);

        $this->edge($askRating, 'btn_0', $saveR1);
        $this->edge($askRating, 'btn_1', $saveR3);
        $this->edge($askRating, 'btn_2', $saveR5);
        $this->edge($askRating, 'fallback', $askRating);

        $this->edge($saveR1, 'out', $askComment);
        $this->edge($saveR3, 'out', $askComment);
        $this->edge($saveR5, 'out', $askComment);

        // Commento: Salta → salva senza commento, testo → salva con commento
        $this->edge($askComment, 'btn_0', $saveFb);       // Salta
        $this->edge($askComment, 'fallback', $saveComment); // Testo libero
        $this->edge($saveComment, 'out', $saveFb);

        $this->edge($saveFb, 'ok', $thanks);
        $this->edge($saveFb, 'errore', $thanks);
        $this->edge($thanks, 'out', $end);
    }

    private function cleanOrphans(): void
    {
        // Nodi che erano parte dei vecchi flussi result/feedback e non hanno
        // più archi in ingresso né sono entry point
        $orphanLabels = [
            'Conferma con score', 'Conferma risultato', 'Non giocata', 'Score non capito',
            'Marca salvato', 'Fine risultato',
            'Chiedi feedback', 'Rating: scarso', 'Rating: medio', 'Rating: ottimo',
            'Chiedi commento', 'Salva commento', 'Salva in DB', 'Ringraziamento', 'Fine feedback',
        ];
        DB::table('flow_nodes')->whereIn('label', $orphanLabels)
            ->where('is_entry', false)
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                  ->from('flow_edges')
                  ->whereColumn('flow_edges.to_node_id', 'flow_nodes.id');
            })
            ->delete();
    }

    public function down(): void
    {
        DB::table('flow_nodes')->where('entry_trigger', 'scheduler:post_match')->delete();
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
