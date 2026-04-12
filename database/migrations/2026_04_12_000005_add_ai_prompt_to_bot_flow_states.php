<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_flow_states', function (Blueprint $table) {
            $table->text('ai_prompt')->nullable()->after('on_enter_actions');
        });
    }

    public function down(): void
    {
        Schema::table('bot_flow_states', function (Blueprint $table) {
            $table->dropColumn('ai_prompt');
        });
    }
};
