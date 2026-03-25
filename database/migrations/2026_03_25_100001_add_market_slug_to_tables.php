<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->string('market_slug')->nullable()->after('asset_id');
        });

        Schema::table('trade_history', function (Blueprint $table) {
            $table->string('market_slug')->nullable()->after('asset_id');
        });
    }

    public function down(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->dropColumn('market_slug');
        });

        Schema::table('trade_history', function (Blueprint $table) {
            $table->dropColumn('market_slug');
        });
    }
};
