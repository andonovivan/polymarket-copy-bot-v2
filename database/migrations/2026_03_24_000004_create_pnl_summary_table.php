<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pnl_summary', function (Blueprint $table) {
            $table->id();
            $table->decimal('total_realized', 16, 4)->default(0);
            $table->unsignedInteger('total_trades')->default(0);
            $table->unsignedInteger('winning_trades')->default(0);
            $table->unsignedInteger('losing_trades')->default(0);
            $table->timestamp('updated_at')->nullable();
        });

        DB::table('pnl_summary')->insert([
            'id' => 1,
            'total_realized' => 0,
            'total_trades' => 0,
            'winning_trades' => 0,
            'losing_trades' => 0,
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('pnl_summary');
    }
};
