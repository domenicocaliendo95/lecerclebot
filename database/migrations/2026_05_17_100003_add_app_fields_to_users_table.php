<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('avatar_path')->nullable()->after('phone');
            $table->text('bio')->nullable()->after('avatar_path');
            $table->timestamp('last_seen_at')->nullable()->after('bio');
            $table->timestamp('app_onboarded_at')->nullable()->after('last_seen_at');
            $table->json('notification_preferences')->nullable()->after('app_onboarded_at');
            $table->enum('privacy_profile', ['public', 'club_only', 'friends_only'])
                  ->default('club_only')->after('notification_preferences');
            $table->boolean('show_in_matchmaking')->default(true)->after('privacy_profile');
            $table->softDeletes()->after('updated_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->dropColumn([
                'avatar_path', 'bio', 'last_seen_at', 'app_onboarded_at',
                'notification_preferences', 'privacy_profile', 'show_in_matchmaking',
            ]);
        });
    }
};
