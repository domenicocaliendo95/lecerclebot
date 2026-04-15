<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nuovo modello di esecuzione: grafo di moduli.
 *
 * Ogni "nodo" del flusso è un'istanza configurata di un Module (dalla registry PHP).
 * Gli "archi" collegano la porta di output di un nodo a quella di input del nodo successivo.
 * Il FlowRunner cammina il grafo; la sessione tiene il cursore corrente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flow_nodes', function (Blueprint $table) {
            $table->id();
            $table->string('module_key', 64);          // es. 'invia_bottoni', 'utente_registrato'
            $table->string('label', 120)->nullable();  // titolo editabile dall'utente nell'editor
            $table->json('config')->nullable();        // parametri specifici del modulo
            $table->json('position')->nullable();      // {x, y} per l'editor visuale
            $table->boolean('is_entry')->default(false); // nodo di ingresso (trigger)
            $table->string('entry_trigger', 64)->nullable(); // es. 'first_message', 'keyword:prenotazioni'
            $table->timestamps();

            $table->index('module_key');
            $table->index('is_entry');
        });

        Schema::create('flow_edges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_node_id')->constrained('flow_nodes')->cascadeOnDelete();
            $table->string('from_port', 64)->default('out');
            $table->foreignId('to_node_id')->constrained('flow_nodes')->cascadeOnDelete();
            $table->string('to_port', 64)->default('in');
            $table->timestamps();

            $table->index(['from_node_id', 'from_port']);
            $table->index('to_node_id');
        });

        Schema::table('bot_sessions', function (Blueprint $table) {
            // Cursore nel grafo. Null = nessun flusso attivo (sessione fresca).
            $table->foreignId('current_node_id')
                ->nullable()
                ->after('state')
                ->constrained('flow_nodes')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bot_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('current_node_id');
        });
        Schema::dropIfExists('flow_edges');
        Schema::dropIfExists('flow_nodes');
    }
};
