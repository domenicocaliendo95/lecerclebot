<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Aggiunge al grafo i passaggi mancanti del flusso prenotazione:
 *
 * 1. AVVERSARIO: dopo "Tipo: con avversario" → "Con chi giocherai?" →
 *    chiedi nome → salva → prosegui a "Quando vuoi giocare?"
 *
 * 2. DURATA: dopo parse_data (ok) → "Quanto vuoi giocare?" [1h/1h30/2h]
 *    → salva durata → prosegui a verifica calendario
 *
 * 3. PAGAMENTO: dopo slot libero → "Come preferisci pagare?" [Online/Di persona/Annulla]
 *    → salva metodo → prosegui a crea prenotazione
 *
 * I nodi esistenti vengono trovati per label (dal seed iniziale) e gli
 * edge vengono rewired per intercalare i nuovi step.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            // ── Trova nodi esistenti per label ──────────────
            $setTypeAvv  = $this->findNode('Tipo: con avversario');
            $setTypeMm   = $this->findNode('Tipo: matchmaking');
            $setTypeSpara= $this->findNode('Tipo: sparapalline');
            $askWhen     = $this->findNode('Chiedi quando');
            $parseWhen   = $this->findNode('Parsa data/ora');
            $checkCal    = $this->findNode('Verifica calendario');
            $slotLibero  = $this->findNode('Slot libero');
            $creaBook    = $this->findNode('Crea booking');
            $menuMain    = $this->findNode('Menu principale');

            if (!$setTypeAvv || !$askWhen || !$parseWhen || !$checkCal || !$slotLibero || !$creaBook || !$menuMain) {
                // Nodi non trovati — skip (forse la migration base non è stata eseguita)
                return;
            }

            // ═══════════════════════════════════════════════
            // 1. AVVERSARIO (solo per "con avversario")
            // ═══════════════════════════════════════════════

            $askOpponent = $this->node('invia_bottoni', [
                'text'    => 'Con chi giocherai?',
                'buttons' => [
                    ['label' => 'Ho un avversario'],
                    ['label' => 'Gioco da solo'],
                ],
            ], ['x' => 100, 'y' => 460], 'Chiedi avversario');

            $askOpponentName = $this->node('chiedi_campo', [
                'question'      => "Come si chiama il tuo avversario? Scrivi nome e cognome.",
                'save_to'       => 'opponent_name',
                'validator'     => 'name',
                'retry_message' => 'Scrivi il nome per esteso (es. Mario Rossi)',
            ], ['x' => 0, 'y' => 580], 'Nome avversario');

            $skipOpponent = $this->node('salva_in_sessione', [
                'assignments' => [['key' => 'opponent_name', 'value' => null]],
            ], ['x' => 200, 'y' => 580], 'Salta avversario');

            // Rewire: set_type_avv → askOpponent (era → askWhen)
            $this->deleteEdge($setTypeAvv, 'out', $askWhen);
            $this->edge($setTypeAvv, 'out', $askOpponent);
            $this->edge($askOpponent, 'btn_0', $askOpponentName);  // Ho un avversario
            $this->edge($askOpponent, 'btn_1', $skipOpponent);     // Gioco da solo
            $this->edge($askOpponent, 'fallback', $askOpponent);   // Ripeti
            $this->edge($askOpponentName, 'ok', $askWhen);
            $this->edge($skipOpponent, 'out', $askWhen);

            // ═══════════════════════════════════════════════
            // 2. DURATA (dopo parse_data ok, prima di verifica calendario)
            // ═══════════════════════════════════════════════

            $askDuration = $this->node('invia_bottoni', [
                'text'    => 'Quanto vuoi giocare?',
                'buttons' => [
                    ['label' => '1 ora'],
                    ['label' => '1 ora e mezza'],
                    ['label' => '2 ore'],
                ],
            ], ['x' => 250, 'y' => 890], 'Scegli durata');

            $saveDur60 = $this->node('salva_in_sessione', [
                'assignments' => [['key' => 'requested_duration_minutes', 'value' => 60]],
            ], ['x' => 100, 'y' => 960], '60 min');

            $saveDur90 = $this->node('salva_in_sessione', [
                'assignments' => [['key' => 'requested_duration_minutes', 'value' => 90]],
            ], ['x' => 250, 'y' => 960], '90 min');

            $saveDur120 = $this->node('salva_in_sessione', [
                'assignments' => [['key' => 'requested_duration_minutes', 'value' => 120]],
            ], ['x' => 400, 'y' => 960], '120 min');

            // Rewire: parse_when (ok) → askDuration (era → checkCal)
            $this->deleteEdge($parseWhen, 'ok', $checkCal);
            $this->edge($parseWhen, 'ok', $askDuration);
            $this->edge($askDuration, 'btn_0', $saveDur60);
            $this->edge($askDuration, 'btn_1', $saveDur90);
            $this->edge($askDuration, 'btn_2', $saveDur120);
            $this->edge($askDuration, 'fallback', $askDuration);
            $this->edge($saveDur60, 'out', $checkCal);
            $this->edge($saveDur90, 'out', $checkCal);
            $this->edge($saveDur120, 'out', $checkCal);

            // ═══════════════════════════════════════════════
            // 3. PAGAMENTO (dopo slot libero, prima di crea booking)
            // ═══════════════════════════════════════════════

            $askPayment = $this->node('invia_bottoni', [
                'text'    => 'Slot {data.requested_friendly} disponibile! Come preferisci pagare?',
                'buttons' => [
                    ['label' => 'Paga online'],
                    ['label' => 'Pago di persona'],
                    ['label' => 'Annulla'],
                ],
            ], ['x' => 150, 'y' => 1170], 'Scegli pagamento');

            $savePayOnline = $this->node('salva_in_sessione', [
                'assignments' => [['key' => 'payment_method', 'value' => 'online']],
            ], ['x' => 50, 'y' => 1280], 'Paga online');

            $savePayLocal = $this->node('salva_in_sessione', [
                'assignments' => [['key' => 'payment_method', 'value' => 'in_loco']],
            ], ['x' => 200, 'y' => 1280], 'Di persona');

            // Rewire: slotLibero → askPayment (era → creaBook)
            $this->deleteEdge($slotLibero, 'out', $creaBook);
            $this->edge($slotLibero, 'out', $askPayment);
            $this->edge($askPayment, 'btn_0', $savePayOnline);
            $this->edge($askPayment, 'btn_1', $savePayLocal);
            $this->edge($askPayment, 'btn_2', $menuMain);        // Annulla → menu
            $this->edge($askPayment, 'fallback', $askPayment);   // Ripeti
            $this->edge($savePayOnline, 'out', $creaBook);
            $this->edge($savePayLocal, 'out', $creaBook);
        });
    }

    public function down(): void
    {
        // Non reversibile in modo pulito — i nodi possono essere rimossi
        // manualmente dall'editor se necessario.
    }

    /* ───────── Helpers ───────── */

    private function findNode(string $label): ?int
    {
        return DB::table('flow_nodes')->where('label', $label)->value('id');
    }

    private function node(string $moduleKey, array $config, array $position, ?string $label = null): int
    {
        return DB::table('flow_nodes')->insertGetId([
            'module_key' => $moduleKey,
            'label'      => $label,
            'config'     => json_encode($config),
            'position'   => json_encode($position),
            'is_entry'   => false,
            'created_at' => now(),
            'updated_at' => now(),
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

    private function deleteEdge(int $fromId, string $fromPort, int $toId): void
    {
        DB::table('flow_edges')
            ->where('from_node_id', $fromId)
            ->where('from_port', $fromPort)
            ->where('to_node_id', $toId)
            ->delete();
    }
};
