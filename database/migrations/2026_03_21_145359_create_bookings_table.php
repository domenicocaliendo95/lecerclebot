<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player1_id')->constrained('users');
            $table->foreignId('player2_id')->nullable()->constrained('users');
            $table->date('booking_date');
            $table->time('start_time');
            $table->time('end_time');
            $table->decimal('price', 8, 2);
            $table->boolean('is_peak')->default(false);
            $table->string('status')->default('pending_match');
            $table->string('gcal_event_id')->nullable();
            $table->string('stripe_payment_link_p1')->nullable();
            $table->string('stripe_payment_link_p2')->nullable();
            $table->string('payment_status_p1')->default('pending');
            $table->string('payment_status_p2')->default('pending');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};