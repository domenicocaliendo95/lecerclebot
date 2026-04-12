<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('bot_messages')->updateOrInsert(
            ['key' => 'saluto_nuovo'],
            [
                'category'    => 'saluti',
                'description' => 'Primo messaggio per utente non registrato ({persona})',
                'text'        => "Ciao! Sono {persona}, il tuo assistente virtuale del circolo Le Cercle Tennis Club di San Gennaro Vesuviano. 🎾\n\nPosso aiutarti a:\n• Prenotare un campo (con avversario o sparapalline)\n• Trovare un avversario del tuo livello\n• Gestire le tue prenotazioni\n\nPer iniziare ho bisogno di registrarti. Dimmi il tuo nome!",
                'created_at'  => $now,
                'updated_at'  => $now,
            ],
        );

        DB::table('bot_messages')->updateOrInsert(
            ['key' => 'saluto_ritorno'],
            [
                'category'    => 'saluti',
                'description' => 'Primo messaggio per utente già registrato ({name}, {persona})',
                'text'        => "Bentornato {name}! Sono {persona}, il tuo assistente di oggi. 🎾\n\nCosa vuoi fare? Puoi anche scrivere:\n• \"prenotazioni\" per gestire le tue prenotazioni\n• \"profilo\" per modificare i tuoi dati\n• \"menu\" per tornare qui in qualsiasi momento",
                'created_at'  => $now,
                'updated_at'  => $now,
            ],
        );

        // Aggiungi anche gli stati NEW nel flow editor
        // (se non esistono già — la migration del 6 aprile potrebbe averli seedati)
        DB::table('bot_flow_states')->updateOrInsert(
            ['state' => 'NEW'],
            [
                'message_key'  => 'saluto_nuovo',
                'updated_at'   => $now,
            ],
        );
    }

    public function down(): void
    {
        DB::table('bot_messages')->whereIn('key', ['saluto_nuovo', 'saluto_ritorno'])->delete();
    }
};
