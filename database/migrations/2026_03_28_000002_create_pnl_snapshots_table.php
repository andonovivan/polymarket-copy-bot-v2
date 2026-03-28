<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pnl_snapshots', function (Blueprint $table) {
            $table->id();
            $table->decimal('realized_pnl', 16, 4)->default(0);
            $table->decimal('unrealized_pnl', 16, 4)->default(0);
            $table->decimal('combined_pnl', 16, 4)->default(0);
            $table->decimal('positions_value', 16, 4)->default(0);
            $table->decimal('total_invested', 16, 4)->default(0);
            $table->integer('open_positions')->default(0);
            $table->timestamp('recorded_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pnl_snapshots');
    }
};
