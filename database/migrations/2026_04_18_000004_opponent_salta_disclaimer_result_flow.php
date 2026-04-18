<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 1. Rinomina bottone "Gioco da solo" → "Salta" nel nodo avversario
 * 2. Aggiunge disclaimer dopo conferma prenotazione (info su risultato)
 * 3. Crea il flusso "Inserisci risultato" come flow separato:
 *    - trigger scheduler:result_request
 *    - chiedi risultato → parse punteggio → conferma → salva
 */
return new class extends Migration
{
    private array $ids = [];

    public function up(): void
    {
        DB::transaction(function () {
            // ═══════════════════════════════════════
            // 1. Rinomina bottone "Gioco da solo" → "Salta"
            // ═══════════════════════════════════════
            $askOpp = DB::table('flow_nodes')->where('label', 'Chiedi avversario')->first();
            if ($askOpp) {
                $config = json_decode($askOpp->config, true) ?? [];
                if (isset($config['buttons'][1]['label'])) {
                    $config['buttons'][1]['label'] = 'Salta';
                }
                DB::table('flow_nodes')->where('id', $askOpp->id)->update([
                    'config' => json_encode($config),
                ]);
            }

            // ═══════════════════════════════════════
            // 2. Disclaimer post-prenotazione
            // ═══════════════════════════════════════
            $bookOk = DB::table('flow_nodes')->where('label', 'Conferma finale')->value('id');
            $fine    = DB::table('flow_nodes')->where('label', 'Fine')
                ->where('module_key', 'fine_flusso')
                ->value('id');

            if ($bookOk && $fine) {
                $disclaimer = $this->node('invia_testo', [
                    'text' => "Dopo la partita, riceverai un messaggio per registrare il risultato. Se il tuo avversario è iscritto al circolo, lo riceverà anche lui — così aggiorniamo la classifica per entrambi 🏆",
                ], ['x' => 150, 'y' => 1520], 'Disclaimer risultato');

                // Rewire: bookOk → disclaimer → fine (era bookOk → fine)
                DB::table('flow_edges')
                    ->where('from_node_id', $bookOk)
                    ->where('to_node_id', $fine)
                    ->delete();

                $this->edge($bookOk, 'out', $disclaimer);
                $this->edge($disclaimer, 'out', $fine);
            }

            // ═══════════════════════════════════════
            // 3. Flusso "Inserisci risultato"
            // ═══════════════════════════════════════
            $this->createResultFlow();
        });
    }

    private function createResultFlow(): void
    {
        // Entry: messaggio con bottoni rapidi
        $entry = $this->node('invia_bottoni', [
            'text'    => "Com'è andata la partita di {slot}? 🎾\n\nPuoi scrivere il punteggio (es. 6-3 6-4) oppure scegliere un'opzione:",
            'buttons' => [
                ['label' => 'Ho vinto'],
                ['label' => 'Ho perso'],
                ['label' => 'Non giocata'],
            ],
        ], ['x' => 0, 'y' => 0], 'Chiedi risultato', isEntry: true, entryTrigger: 'scheduler:result_request');

        // Parse risultato (cattura sia bottoni che punteggio testuale)
        $parse = $this->node('parse_risultato', [
            'source' => 'input',
        ], ['x' => 0, 'y' => 140], 'Parsa punteggio');

        // Conferma con punteggio
        $confirmScore = $this->node('invia_testo', [
            'text' => "Risultato registrato: {data.match_score}\nGrazie! 🏆",
        ], ['x' => -100, 'y' => 280], 'Conferma con score');

        // Conferma senza punteggio (solo vinto/perso)
        $confirmSimple = $this->node('invia_testo', [
            'text' => 'Risultato registrato. Grazie! 🏆',
        ], ['x' => -100, 'y' => 280], 'Conferma risultato');

        // Non giocata
        $notPlayed = $this->node('invia_testo', [
            'text' => 'OK, partita segnata come non giocata.',
        ], ['x' => 100, 'y' => 280], 'Non giocata');

        // Non capito → richiedi
        $retry = $this->node('invia_testo', [
            'text' => "Non ho capito il punteggio 🤔\nScrivi nel formato 6-3 6-4 oppure premi un bottone.",
        ], ['x' => 200, 'y' => 280], 'Score non capito');

        // Salva risultato
        $save = $this->node('salva_in_sessione', [
            'assignments' => [
                ['key' => 'result_saved', 'value' => true],
            ],
        ], ['x' => 0, 'y' => 420], 'Marca salvato');

        // Fine
        $end = $this->node('fine_flusso', [], ['x' => 0, 'y' => 540], 'Fine risultato');

        // Edges
        // Entry: i bottoni portano al parser (fase 2 resume matcha il bottone,
        // ma in realtà l'input testuale va al parser che è il nodo successivo)
        // Trick: il btn match di invia_bottoni cattura "Ho vinto"/"Ho perso"/"Non giocata"
        // e li passa al parse_risultato che li capisce.
        $this->edge($entry, 'btn_0', $parse);    // Ho vinto → parse "ho vinto"
        $this->edge($entry, 'btn_1', $parse);    // Ho perso → parse "ho perso"
        $this->edge($entry, 'btn_2', $parse);    // Non giocata → parse
        $this->edge($entry, 'fallback', $parse); // Testo libero (es. "6-3 6-4") → parse

        $this->edge($parse, 'ok', $confirmSimple);
        $this->edge($parse, 'non_giocata', $notPlayed);
        $this->edge($parse, 'non_capito', $retry);
        $this->edge($retry, 'out', $entry);    // Loop: richiedi di nuovo

        $this->edge($confirmSimple, 'out', $save);
        $this->edge($notPlayed, 'out', $save);
        $this->edge($save, 'out', $end);
    }

    public function down(): void
    {
        DB::table('flow_nodes')->where('entry_trigger', 'scheduler:result_request')->delete();
        DB::table('flow_nodes')->where('label', 'Disclaimer risultato')->delete();
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

    private function edge(int $fromId, string $fromPort, int $toId): void
    {
        DB::table('flow_edges')->insert([
            'from_node_id' => $fromId,
            'from_port'    => $fromPort,
            'to_node_id'   => $toId,
            'to_port'      => 'in',
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }
};
