<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('pricing_rules', function (Blueprint $table) {
            // Durata specifica dello slot (null = si applica a qualsiasi durata)
            $table->smallInteger('duration_minutes')->nullable()->after('end_time');
            // Data specifica (override su day_of_week, per festivi ecc.)
            $table->date('specific_date')->nullable()->after('day_of_week');
            // Prezzo piatto per slot (sostituisce price_per_hour per nuovi record)
            $table->decimal('price', 8, 2)->nullable()->after('price_per_hour');
            // Priorità: regola più specifica vince
            $table->smallInteger('priority')->default(0)->after('label');
            $table->boolean('is_active')->default(true)->after('priority');
        });
    }
    public function down(): void {
        Schema::table('pricing_rules', function (Blueprint $table) {
            $table->dropColumn(['duration_minutes', 'specific_date', 'price', 'priority', 'is_active']);
        });
    }
};
