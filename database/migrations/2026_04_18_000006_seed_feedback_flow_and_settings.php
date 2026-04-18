<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Crea il flusso "Richiesta feedback" e aggiorna bot_settings con la
 * config per i reminder post-partita (risultato + feedback).
 *
 * Flusso feedback:
 *   [scheduler:feedback_request] → rating [Scarso/Medio/Ottimo]
 *   → salva rating → chiedi commento → salva feedback → fine
 */
return new class extends Migration
{
    private array $ids = [];

    public function up(): void
    {
        DB::transaction(function () {
            $this->createFeedbackFlow();
            $this->updateSettings();
        });
    }

    private function createFeedbackFlow(): void
    {
        $entry = $this->node('invia_bottoni', [
            'text'    => "Com'è stata la tua esperienza al circolo per la partita di {slot}? 🎾",
            'buttons' => [
                ['label' => '⭐ Scarso'],
                ['label' => '⭐⭐ Medio'],
                ['label' => '⭐⭐⭐ Ottimo'],
            ],
        ], ['x' => 0, 'y' => 0], 'Chiedi feedback', isEntry: true, entryTrigger: 'scheduler:feedback_request');

        // Rami rating (salva valore numerico in sessione)
        $save1 = $this->node('salva_in_sessione', [
            'assignments' => [['key' => 'feedback_rating', 'value' => 1]],
        ], ['x' => -150, 'y' => 140], 'Rating: scarso');

        $save3 = $this->node('salva_in_sessione', [
            'assignments' => [['key' => 'feedback_rating', 'value' => 3]],
        ], ['x' => 0, 'y' => 140], 'Rating: medio');

        $save5 = $this->node('salva_in_sessione', [
            'assignments' => [['key' => 'feedback_rating', 'value' => 5]],
        ], ['x' => 150, 'y' => 140], 'Rating: ottimo');

        // Chiedi commento
        $askComment = $this->node('invia_bottoni', [
            'text'    => 'Grazie! Vuoi lasciarci un commento?',
            'buttons' => [
                ['label' => 'Salta'],
            ],
        ], ['x' => 0, 'y' => 280], 'Chiedi commento');

        // Salva commento da input testuale
        $saveComment = $this->node('salva_in_sessione', [
            'assignments' => [['key' => 'feedback_comment', 'value' => '{data.last_input}']],
        ], ['x' => -100, 'y' => 420], 'Salva commento');

        // Salva feedback nel DB
        $saveFeedback = $this->node('salva_feedback', [
            'type' => 'post_match',
        ], ['x' => 0, 'y' => 540], 'Salva in DB');

        $thanks = $this->node('invia_testo', [
            'text' => 'Grazie per il feedback! 🙏',
        ], ['x' => 0, 'y' => 660], 'Ringraziamento');

        $end = $this->node('fine_flusso', [], ['x' => 0, 'y' => 780], 'Fine feedback');

        // Edges
        $this->edge($entry, 'btn_0', $save1);
        $this->edge($entry, 'btn_1', $save3);
        $this->edge($entry, 'btn_2', $save5);
        $this->edge($entry, 'fallback', $entry);

        $this->edge($save1, 'out', $askComment);
        $this->edge($save3, 'out', $askComment);
        $this->edge($save5, 'out', $askComment);

        // "Salta" (btn_0) → salva feedback senza commento
        $this->edge($askComment, 'btn_0', $saveFeedback);
        // Testo libero (fallback = commento scritto) → salva commento → salva feedback
        $this->edge($askComment, 'fallback', $saveComment);
        $this->edge($saveComment, 'out', $saveFeedback);

        $this->edge($saveFeedback, 'ok', $thanks);
        $this->edge($saveFeedback, 'errore', $thanks);
        $this->edge($thanks, 'out', $end);
    }

    private function updateSettings(): void
    {
        // Aggiungi config post_match a bot_settings
        $resultNode = DB::table('flow_nodes')
            ->where('entry_trigger', 'scheduler:result_request')
            ->value('id');

        $feedbackNode = DB::table('flow_nodes')
            ->where('entry_trigger', 'scheduler:feedback_request')
            ->value('id');

        DB::table('bot_settings')->updateOrInsert(
            ['key' => 'post_match'],
            ['value' => json_encode([
                'result_request' => [
                    'enabled'       => true,
                    'hours_after'   => 1,
                    'flow_node_id'  => $resultNode,
                ],
                'feedback_request' => [
                    'enabled'       => true,
                    'hours_after'   => 3,
                    'flow_node_id'  => $feedbackNode,
                ],
            ]), 'updated_at' => now()],
        );
    }

    public function down(): void
    {
        DB::table('flow_nodes')->where('entry_trigger', 'scheduler:feedback_request')->delete();
        DB::table('bot_settings')->where('key', 'post_match')->delete();
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
