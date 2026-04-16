<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Moduli compositi: sotto-grafi riusabili come moduli.
 *
 * Un flow_composite è la "firma" del modulo (key, label, icon, descrizione).
 * flow_composite_nodes e flow_composite_edges contengono il sotto-grafo
 * interno, con la stessa semantica dei flow_nodes/edges principali.
 *
 * A runtime:
 *  - il ModuleRegistry espone ogni composito come modulo virtuale
 *  - quando il FlowRunner esegue un nodo del grafo principale che richiama
 *    un composito, "discende" nel sotto-grafo (stack in session.data)
 *  - i punti di uscita sono nodi con modulo `composite_output`
 *  - quando il sotto-grafo emette su un composite_output, il runner
 *    "risale" e continua dal nodo parent sulla porta configurata
 *
 * Convenzione: il nodo d'ingresso del sotto-grafo è l'unico con `is_entry=true`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flow_composites', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique();
            $table->string('label', 120);
            $table->text('description')->nullable();
            $table->string('icon', 64)->nullable();
            $table->string('category', 32)->default('custom');
            $table->timestamps();
        });

        Schema::create('flow_composite_nodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('composite_id')->constrained('flow_composites')->cascadeOnDelete();
            $table->string('module_key', 64);
            $table->string('label', 120)->nullable();
            $table->json('config')->nullable();
            $table->json('position')->nullable();
            $table->boolean('is_entry')->default(false); // entry del sotto-grafo
            $table->timestamps();

            $table->index(['composite_id', 'is_entry']);
        });

        Schema::create('flow_composite_edges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('composite_id')->constrained('flow_composites')->cascadeOnDelete();
            $table->foreignId('from_node_id')->constrained('flow_composite_nodes')->cascadeOnDelete();
            $table->string('from_port', 64)->default('out');
            $table->foreignId('to_node_id')->constrained('flow_composite_nodes')->cascadeOnDelete();
            $table->string('to_port', 64)->default('in');
            $table->timestamps();

            $table->index(['composite_id', 'from_node_id', 'from_port']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flow_composite_edges');
        Schema::dropIfExists('flow_composite_nodes');
        Schema::dropIfExists('flow_composites');
    }
};
