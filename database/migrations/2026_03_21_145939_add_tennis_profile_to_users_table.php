<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone')->unique()->nullable();
            $table->date('birthdate')->nullable();
            $table->boolean('is_fit')->default(false);
            $table->string('fit_rating')->nullable(); // es. 4.1, 3.3, NC
            $table->tinyInteger('self_level')->nullable(); // 1-5
            $table->integer('elo_rating')->default(1200);
            $table->integer('matches_played')->default(0);
            $table->integer('matches_won')->default(0);
            $table->boolean('is_elo_established')->default(false);
            $table->json('preferred_slots')->nullable(); // ['morning','afternoon','evening']
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'phone', 'birthdate', 'is_fit', 'fit_rating',
                'self_level', 'elo_rating', 'matches_played',
                'matches_won', 'is_elo_established', 'preferred_slots'
            ]);
        });
    }
};