<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Single record per ora (Le Cercle), ma la tabella esiste per il rewrite
 * multitenant Courtly. App tira branding da qui via GET /v1/app/club.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clubs', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('tagline')->nullable();
            $table->string('logo_path')->nullable();
            $table->string('primary_color')->default('#6B8068');
            $table->string('secondary_color')->default('#ECE3CE');
            $table->string('accent_color')->default('#C89B5A');
            $table->string('address')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('timezone')->default('Europe/Rome');
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        // Seed single record per ora
        DB::table('clubs')->insert([
            'slug' => 'le-cercle-club',
            'name' => 'Le Cercle Club',
            'tagline' => 'Tennis Club',
            'primary_color' => '#6B8068',
            'secondary_color' => '#ECE3CE',
            'accent_color' => '#C89B5A',
            'address' => 'San Gennaro Vesuviano (NA)',
            'timezone' => 'Europe/Rome',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('clubs');
    }
};
