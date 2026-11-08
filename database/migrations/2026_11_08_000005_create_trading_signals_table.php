<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trading_signals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('scanned_pair_id')->constrained()->cascadeOnDelete();
            $table->string('timeframe', 8)->default('4H');
            $table->timestamp('candle_at');
            $table->decimal('ema_100', 32, 12)->nullable();
            $table->decimal('ema_200', 32, 12)->nullable();
            $table->decimal('ha_open', 32, 12)->nullable();
            $table->decimal('ha_close', 32, 12)->nullable();
            $table->decimal('volume', 32, 12)->nullable();
            $table->decimal('volume_sma', 32, 12)->nullable();
            $table->boolean('ema_bullish')->nullable();
            $table->boolean('ha_bullish')->nullable();
            $table->boolean('volume_confirmed')->nullable();
            $table->boolean('is_qualified')->default(false);
            $table->text('evaluation_reason');
            $table->timestamp('evaluated_at');
            $table->timestamps();

            $table->unique(['scanned_pair_id', 'timeframe', 'candle_at'], 'trading_signals_pair_time_candle_unique');
            $table->index(['is_qualified', 'candle_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trading_signals');
    }
};
