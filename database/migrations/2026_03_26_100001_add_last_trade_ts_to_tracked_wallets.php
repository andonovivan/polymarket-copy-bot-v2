<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tracked_wallets', function (Blueprint $table) {
            $table->unsignedBigInteger('last_trade_ts')->nullable()->after('pause_reason');
        });
    }

    public function down(): void
    {
        Schema::table('tracked_wallets', function (Blueprint $table) {
            $table->dropColumn('last_trade_ts');
        });
    }
};
