<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trade_history', function (Blueprint $table) {
            $table->id();
            $table->string('asset_id');
            $table->decimal('buy_price', 16, 8);
            $table->decimal('sell_price', 16, 8);
            $table->decimal('shares', 16, 4);
            $table->decimal('pnl', 16, 4);
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trade_history');
    }
};
