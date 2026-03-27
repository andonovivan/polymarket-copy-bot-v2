<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->string('market_question', 500)->nullable()->after('market_slug');
            $table->string('market_image', 500)->nullable()->after('market_question');
            $table->string('outcome', 50)->nullable()->after('market_image');
        });

        Schema::table('trade_history', function (Blueprint $table) {
            $table->string('market_question', 500)->nullable()->after('market_slug');
            $table->string('market_image', 500)->nullable()->after('market_question');
            $table->string('outcome', 50)->nullable()->after('market_image');
        });
    }

    public function down(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->dropColumn(['market_question', 'market_image', 'outcome']);
        });

        Schema::table('trade_history', function (Blueprint $table) {
            $table->dropColumn(['market_question', 'market_image', 'outcome']);
        });
    }
};
