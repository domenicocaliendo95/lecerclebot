<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->enum('created_via', ['bot_whatsapp', 'app', 'admin_panel'])
                  ->default('bot_whatsapp')->after('status');
            $table->text('notes')->nullable()->after('created_via');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['created_via', 'notes']);
        });
    }
};
