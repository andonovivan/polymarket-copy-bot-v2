<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->decimal('tp_price', 16, 8)->nullable()->after('current_price');
            $table->decimal('sl_price', 16, 8)->nullable()->after('tp_price');
        });
    }

    public function down(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->dropColumn(['tp_price', 'sl_price']);
        });
    }
};
