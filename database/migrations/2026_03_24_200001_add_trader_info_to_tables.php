<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tracked_wallets', function (Blueprint $table) {
            $table->string('name', 100)->nullable()->after('address');
            $table->string('profile_slug', 100)->nullable()->after('name');
        });

        Schema::table('positions', function (Blueprint $table) {
            $table->string('copied_from_wallet', 42)->nullable()->after('asset_id');
        });

        Schema::table('trade_history', function (Blueprint $table) {
            $table->string('copied_from_wallet', 42)->nullable()->after('asset_id');
        });
    }

    public function down(): void
    {
        Schema::table('tracked_wallets', function (Blueprint $table) {
            $table->dropColumn(['name', 'profile_slug']);
        });

        Schema::table('positions', function (Blueprint $table) {
            $table->dropColumn('copied_from_wallet');
        });

        Schema::table('trade_history', function (Blueprint $table) {
            $table->dropColumn('copied_from_wallet');
        });
    }
};
