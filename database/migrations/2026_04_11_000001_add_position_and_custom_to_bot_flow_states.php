<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_flow_states', function (Blueprint $table) {
            // Posizione del nodo nel flow editor visuale (React Flow).
            // Formato: {"x": 0, "y": 0}. Null = layout automatico (dagre).
            $table->json('position')->nullable()->after('sort_order');

            // Flag che distingue gli stati creati dal pannello (true)
            // dagli stati built-in del codice (false). I custom possono essere
            // SOLO di tipo "simple" e sono gli unici eliminabili dal pannello.
            $table->boolean('is_custom')->default(false)->after('position');
        });
    }

    public function down(): void
    {
        Schema::table('bot_flow_states', function (Blueprint $table) {
            $table->dropColumn(['position', 'is_custom']);
        });
    }
};
