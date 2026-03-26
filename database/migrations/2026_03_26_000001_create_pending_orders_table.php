<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pending_orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_id')->unique();
            $table->string('asset_id')->index();
            $table->string('side', 4);  // BUY or SELL
            $table->decimal('price', 16, 8);
            $table->decimal('size', 16, 4);
            $table->decimal('amount_usdc', 16, 4)->nullable();  // BUY only: price * size
            $table->string('copied_from_wallet')->nullable();
            $table->string('market_slug')->nullable();
            $table->string('status', 20)->default('live')->index();  // live, delayed, filled, cancelled
            $table->decimal('fill_price', 16, 8)->nullable();
            $table->timestamp('placed_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_orders');
    }
};
