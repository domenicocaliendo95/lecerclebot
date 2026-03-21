<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pricing_rules', function (Blueprint $table) {
            $table->id();
            $table->tinyInteger('day_of_week')->nullable(); // 0=domenica, 6=sabato, null=tutti
            $table->time('start_time');
            $table->time('end_time');
            $table->decimal('price_per_hour', 8, 2);
            $table->boolean('is_peak')->default(false);
            $table->string('label')->nullable(); // es. "Peak serale", "Weekend"
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pricing_rules');
    }
};