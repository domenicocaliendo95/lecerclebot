<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('elo_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('match_result_id')->nullable()->constrained('match_results')->nullOnDelete();
            $table->integer('elo_before');
            $table->integer('elo_after');
            $table->integer('delta'); // elo_after - elo_before (positivo o negativo)
            $table->string('reason')->default('match'); // match / initial / admin_correction
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('elo_history');
    }
};
