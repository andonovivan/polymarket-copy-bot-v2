<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trade_history', function (Blueprint $table) {
            $table->string('exit_reason', 20)->nullable()->after('pnl');
        });
    }

    public function down(): void
    {
        Schema::table('trade_history', function (Blueprint $table) {
            $table->dropColumn('exit_reason');
        });
    }
};
