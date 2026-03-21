<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('match_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings');
            $table->foreignId('winner_id')->nullable()->constrained('users');
            $table->string('score')->nullable();
            $table->integer('player1_elo_before')->nullable();
            $table->integer('player1_elo_after')->nullable();
            $table->integer('player2_elo_before')->nullable();
            $table->integer('player2_elo_after')->nullable();
            $table->boolean('player1_confirmed')->default(false);
            $table->boolean('player2_confirmed')->default(false);
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('match_results');
    }
};