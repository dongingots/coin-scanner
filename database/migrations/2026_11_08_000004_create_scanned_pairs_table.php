<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scanned_pairs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('trading_strategy_id')->constrained()->cascadeOnDelete();
            $table->string('symbol', 50);
            $table->string('base_coin', 20);
            $table->string('quote_coin', 20);
            $table->string('contract_type', 32);
            $table->decimal('turnover_24h_usdt', 32, 8)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_scanned_at');
            $table->timestamps();

            $table->unique(['trading_strategy_id', 'symbol']);
            $table->index(['trading_strategy_id', 'is_active', 'symbol']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scanned_pairs');
    }
};
