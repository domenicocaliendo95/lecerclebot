<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feedbacks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained('bookings')->nullOnDelete();

            // Tipo di feedback
            // match_feedback: dopo una partita (È andata bene? Punteggio? Problemi?)
            // general:        feedback generico sul circolo
            // complaint:      segnalazione problema specifico
            $table->string('type')->default('general');

            // Rating opzionale 1–5
            $table->tinyInteger('rating')->nullable()->unsigned();

            // Risposte strutturate: [{question: "...", answer: "..."}]
            // Permette di aggiungere domande future senza cambiare schema
            $table->json('content')->nullable();

            // Dati liberi extra: stato sessione, contesto, flag admin
            $table->json('metadata')->nullable();

            // Letto dall'admin?
            $table->boolean('is_read')->default(false);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feedbacks');
    }
};
