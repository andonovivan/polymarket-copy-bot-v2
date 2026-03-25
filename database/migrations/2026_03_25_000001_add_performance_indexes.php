<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->index('copied_from_wallet');
        });

        Schema::table('trade_history', function (Blueprint $table) {
            $table->index('copied_from_wallet');
            $table->index('closed_at');
            $table->index('opened_at');
        });

        Schema::table('tracked_wallets', function (Blueprint $table) {
            $table->index('is_paused');
        });
    }

    public function down(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->dropIndex(['copied_from_wallet']);
        });

        Schema::table('trade_history', function (Blueprint $table) {
            $table->dropIndex(['copied_from_wallet']);
            $table->dropIndex(['closed_at']);
            $table->dropIndex(['opened_at']);
        });

        Schema::table('tracked_wallets', function (Blueprint $table) {
            $table->dropIndex(['is_paused']);
        });
    }
};
