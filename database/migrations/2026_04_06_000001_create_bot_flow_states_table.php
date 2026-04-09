<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_flow_states', function (Blueprint $table) {
            $table->string('state', 30)->primary();
            $table->string('type', 10);              // 'simple' | 'complex'
            $table->string('message_key', 100);       // FK bot_messages
            $table->string('fallback_key', 100)->nullable();
            $table->json('buttons')->nullable();      // [{label, target_state, value?, side_effect?}]
            $table->string('category', 50);
            $table->string('description')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_flow_states');
    }
};
