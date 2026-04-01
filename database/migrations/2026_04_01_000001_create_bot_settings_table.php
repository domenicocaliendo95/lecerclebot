<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->json('value');
            $table->timestamps();
        });

        // Seed default reminder settings
        DB::table('bot_settings')->insert([
            [
                'key'   => 'reminders',
                'value' => json_encode([
                    'enabled' => true,
                    'slots'   => [
                        ['hours_before' => 24, 'enabled' => true],
                        ['hours_before' => 2,  'enabled' => true],
                    ],
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_settings');
    }
};
