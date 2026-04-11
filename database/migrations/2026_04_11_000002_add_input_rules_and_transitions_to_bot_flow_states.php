<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_flow_states', function (Blueprint $table) {
            // Regole di validazione/parsing dell'input quando l'utente NON
            // clicca un bottone (testo libero). Array ordinato di rule object.
            //
            // Schema rule (campi comuni):
            //  - type           : 'name' | 'integer_range' | 'mapping' | 'regex' | 'free_text'
            //  - save_to        : 'profile.X' | 'data.X' | null
            //  - next_state     : stato target dopo match (string, opzionale)
            //  - error_key      : message_key da usare in caso di mancato match
            //  - side_effect    : whitelist applicata dopo il match (opzionale)
            //  + campi specifici per type (vedi RuleEvaluator)
            $table->json('input_rules')->nullable()->after('buttons');

            // Transizioni condizionali valutate DOPO che bottoni e input rules
            // hanno determinato il target. Permettono di "fork-are" in base ai
            // dati salvati nella sessione.
            //
            // Schema transition object:
            //  - if    : { "data.field": "value" }   (uguaglianza, tutti i match AND)
            //  - then  : nome stato target
            // Una transition senza `if` è il default ("else").
            $table->json('transitions')->nullable()->after('input_rules');
        });
    }

    public function down(): void
    {
        Schema::table('bot_flow_states', function (Blueprint $table) {
            $table->dropColumn(['input_rules', 'transitions']);
        });
    }
};
