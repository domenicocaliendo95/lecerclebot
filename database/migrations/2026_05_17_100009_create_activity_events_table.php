<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Feed sociale. Eventi generati lato server (match vinto, iscritto torneo, ecc.).
 * NO update/delete dall'app utente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 50); // booking_created, match_won, match_lost, joined_tournament, event_published, ...
            $table->json('payload')->nullable();
            $table->enum('visibility', ['public', 'club', 'friends', 'private'])->default('club');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
            $table->index(['club_id', 'visibility', 'created_at']);
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_events');
    }
};
