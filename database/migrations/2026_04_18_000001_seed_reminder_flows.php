<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Crea i mini-flussi per i reminder prenotazioni.
 *
 * Ogni reminder è un sotto-flusso indipendente nel grafo:
 *   1. Nodo invia_bottoni (messaggio + bottoni, mandato dal scheduler)
 *   2. Se "OK" → conferma e fine
 *   3. Se "Disdici" → cancella prenotazione → conferma → fine
 *
 * Il scheduler legge il nodo dal flow_node_id salvato in bot_settings.reminders,
 * ne estrae testo e bottoni, li manda via adapter, e setta current_node_id
 * sulla sessione. Quando l'utente risponde, il FlowRunner riprende dal nodo
 * in modalità "resume" e segue l'edge del bottone cliccato.
 *
 * I nodi hanno is_entry=true con trigger "scheduler:*" così appaiono come
 * flussi separati nell'editor ma non scattano mai su messaggi utente normali.
 */
return new class extends Migration
{
    private array $ids = [];

    public function up(): void
    {
        DB::transaction(function () {
            $this->createReminderFlow('reminder_24h', 'Promemoria 24h', 'scheduler:reminder_24h',
                "Ciao {name}! Ti ricordo la prenotazione di {slot} 🎾 A domani!");

            $this->createReminderFlow('reminder_2h', 'Promemoria 2h', 'scheduler:reminder_2h',
                "Tra poco si gioca! Prenotazione {slot} 🎾 Sei pronto?");

            // Aggiorna bot_settings.reminders con i flow_node_id
            $settings = DB::table('bot_settings')->where('key', 'reminders')->first();
            $value = $settings ? json_decode($settings->value, true) : [
                'enabled' => true,
                'slots'   => [],
            ];

            $value['slots'] = [
                ['hours_before' => 24, 'enabled' => true, 'flow_node_id' => $this->ids['reminder_24h_msg']],
                ['hours_before' => 2,  'enabled' => true, 'flow_node_id' => $this->ids['reminder_2h_msg']],
            ];

            DB::table('bot_settings')->updateOrInsert(
                ['key' => 'reminders'],
                ['value' => json_encode($value), 'updated_at' => now()],
            );
        });
    }

    private function createReminderFlow(string $prefix, string $label, string $trigger, string $defaultMessage): void
    {
        // 1. Nodo messaggio + bottoni (entry point del reminder)
        $msgId = $this->node("{$prefix}_msg", 'invia_bottoni', [
            'text'    => $defaultMessage,
            'buttons' => [
                ['label' => 'OK 👍'],
                ['label' => 'Disdici'],
            ],
        ], ['x' => 0, 'y' => 0], $label, isEntry: true, entryTrigger: $trigger);

        // 2. Ramo "OK" → messaggio conferma → fine
        $okId = $this->node("{$prefix}_ok", 'invia_testo', [
            'text' => 'Perfetto, ci vediamo! 🎾',
        ], ['x' => -150, 'y' => 150], 'Conferma');

        $okEnd = $this->node("{$prefix}_ok_end", 'fine_flusso', [], ['x' => -150, 'y' => 280], 'Fine');

        // 3. Ramo "Disdici" → cancella prenotazione → messaggio → fine
        $cancelId = $this->node("{$prefix}_cancel", 'cancella_prenotazione', [], ['x' => 150, 'y' => 150], 'Cancella');

        $cancelOk = $this->node("{$prefix}_cancel_msg", 'invia_testo', [
            'text' => 'Prenotazione cancellata ✅',
        ], ['x' => 100, 'y' => 280], 'Cancellata');

        $cancelErr = $this->node("{$prefix}_cancel_err", 'invia_testo', [
            'text' => 'Non sono riuscito a cancellare, contattaci direttamente.',
        ], ['x' => 250, 'y' => 280], 'Errore cancel');

        $cancelEnd = $this->node("{$prefix}_cancel_end", 'fine_flusso', [], ['x' => 150, 'y' => 400], 'Fine');

        // Edges
        $this->edge($msgId, 'btn_0', $okId);
        $this->edge($msgId, 'btn_1', $cancelId);
        $this->edge($msgId, 'fallback', $okId); // se scrivono altro, tratta come OK
        $this->edge($okId, 'out', $okEnd);
        $this->edge($cancelId, 'ok', $cancelOk);
        $this->edge($cancelId, 'errore', $cancelErr);
        $this->edge($cancelOk, 'out', $cancelEnd);
        $this->edge($cancelErr, 'out', $cancelEnd);
    }

    private function node(
        string $key, string $moduleKey, array $config, array $position,
        ?string $label = null, bool $isEntry = false, ?string $entryTrigger = null
    ): int {
        $id = DB::table('flow_nodes')->insertGetId([
            'module_key'    => $moduleKey,
            'label'         => $label,
            'config'        => json_encode($config),
            'position'      => json_encode($position),
            'is_entry'      => $isEntry,
            'entry_trigger' => $entryTrigger,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
        $this->ids[$key] = $id;
        return $id;
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

    public function down(): void
    {
        // I nodi vengono cancellati manualmente (cascade sugli edges)
        DB::table('flow_nodes')->where('entry_trigger', 'like', 'scheduler:reminder_%')->delete();
    }
};
