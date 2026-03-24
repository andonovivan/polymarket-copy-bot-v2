<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->decimal('current_price', 16, 8)->nullable()->after('buy_price');
            $table->string('market_status', 20)->default('active')->after('current_price');
            $table->timestamp('price_updated_at')->nullable()->after('market_status');
        });
    }

    public function down(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->dropColumn(['current_price', 'market_status', 'price_updated_at']);
        });
    }
};
