<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tournament_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('status', ['pending_payment', 'registered', 'confirmed', 'withdrew', 'eliminated', 'winner'])
                  ->default('registered');
            $table->integer('seed')->nullable();
            $table->enum('payment_status', ['not_required', 'pending', 'paid', 'refunded'])->default('not_required');
            $table->timestamp('registered_at')->useCurrent();
            $table->timestamps();

            $table->unique(['tournament_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tournament_participants');
    }
};
