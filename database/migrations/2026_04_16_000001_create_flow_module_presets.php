<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Preset di moduli: un "modulo virtuale" con configurazione preimpostata su
 * un modulo base esistente.
 *
 * Esempio: l'utente crea un preset "chiedi_nome" che usa la base
 * `chiedi_campo` con config_defaults validator=name + question preimpostata.
 * Nel picker appare come modulo a sé; a runtime il ModuleRegistry risolve
 * al base module fondendo config_defaults con la config del nodo.
 *
 * L'abilitazione on/off dei moduli vive invece in bot_settings come
 * record `flow_module_toggles` = {module_key: bool}.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flow_module_presets', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique();
            $table->string('base_module_key', 64);
            $table->string('label', 120);
            $table->text('description')->nullable();
            $table->string('icon', 64)->nullable();
            $table->string('category', 32)->nullable(); // override categoria del base
            $table->json('config_defaults')->nullable();
            $table->timestamps();

            $table->index('base_module_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flow_module_presets');
    }
};
