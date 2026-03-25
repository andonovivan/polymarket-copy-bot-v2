<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tracked_wallets', function (Blueprint $table) {
            $table->boolean('is_paused')->default(false)->after('profile_slug');
            $table->timestamp('paused_at')->nullable()->after('is_paused');
            $table->string('pause_reason')->nullable()->after('paused_at');
        });
    }

    public function down(): void
    {
        Schema::table('tracked_wallets', function (Blueprint $table) {
            $table->dropColumn(['is_paused', 'paused_at', 'pause_reason']);
        });
    }
};
