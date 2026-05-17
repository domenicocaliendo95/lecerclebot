<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Eventi sociali (aperitivi, clinic, cene). Distinti dai tornei: niente bracket.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->nullable()->constrained()->nullOnDelete();
            $table->string('slug')->unique();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('cover_path')->nullable();
            $table->enum('kind', ['aperitivo', 'cena', 'corso', 'clinic', 'esibizione', 'festa', 'altro'])
                  ->default('altro');
            $table->string('location')->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->boolean('registration_required')->default(true);
            $table->timestamp('registration_opens_at')->nullable();
            $table->timestamp('registration_closes_at')->nullable();
            $table->integer('max_participants')->nullable();
            $table->boolean('allow_plus_ones')->default(false);
            $table->tinyInteger('max_plus_ones')->nullable();
            $table->decimal('fee', 8, 2)->nullable();
            $table->enum('visibility', ['members_only', 'public'])->default('members_only');
            $table->enum('status', ['draft', 'published', 'cancelled', 'completed'])->default('draft');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['club_id', 'status', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
