<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trading_strategies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->unsignedSmallInteger('volume_sma_period')->default(20);
            $table->decimal('minimum_turnover_24h_usdt', 32, 8)->nullable();
            $table->boolean('exclude_btc')->default(true);
            $table->boolean('exclude_eth')->default(true);
            $table->boolean('exclude_stablecoins')->default(true);
            $table->json('excluded_symbols')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamps();

            $table->index(['is_active', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trading_strategies');
    }
};
