<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Introduce il supporto multi-canale nelle sessioni del bot.
 *
 * Le sessioni smettono di essere chiavate su `phone` (specifico di WhatsApp)
 * e diventano chiavate su `(channel, external_id)`. Per backward compat la
 * colonna `phone` resta ma diventa nullable: per i canali che non hanno un
 * concetto di telefono (webchat, app) sarà null.
 *
 * Back-fill: le sessioni esistenti vengono promosse a `channel='whatsapp'` e
 * `external_id=phone`.
 *
 * Crea anche `webchat_outbox` per bufferizzare i messaggi in uscita del
 * canale webchat (letti via polling dal client).
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Aggiungi channel + external_id
        Schema::table('bot_sessions', function (Blueprint $table) {
            $table->string('channel', 32)->default('whatsapp')->after('phone');
            $table->string('external_id', 128)->nullable()->after('channel');
        });

        // 2. Back-fill delle sessioni esistenti
        DB::statement('UPDATE bot_sessions SET external_id = phone WHERE external_id IS NULL');

        // 3. Rendi external_id NOT NULL ora che è popolato
        Schema::table('bot_sessions', function (Blueprint $table) {
            $table->string('external_id', 128)->nullable(false)->change();
        });

        // 4. Drop unique su phone, unique su (channel, external_id)
        Schema::table('bot_sessions', function (Blueprint $table) {
            $table->dropUnique(['phone']);
        });
        Schema::table('bot_sessions', function (Blueprint $table) {
            $table->unique(['channel', 'external_id']);
        });

        // 5. Phone diventa nullable (non tutti i canali ce l'hanno)
        Schema::table('bot_sessions', function (Blueprint $table) {
            $table->string('phone')->nullable()->change();
        });

        // 6. Outbox webchat
        Schema::create('webchat_outbox', function (Blueprint $table) {
            $table->id();
            $table->string('external_id', 128)->index();
            $table->json('payload');
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webchat_outbox');

        Schema::table('bot_sessions', function (Blueprint $table) {
            $table->dropUnique(['channel', 'external_id']);
            $table->dropColumn(['channel', 'external_id']);
            $table->string('phone')->nullable(false)->change();
            $table->unique('phone');
        });
    }
};
