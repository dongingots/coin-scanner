<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trading_strategies', function (Blueprint $table): void {
            $table->boolean('ema_trend_filter_enabled')->default(true);
        });

        Schema::table('scan_runs', function (Blueprint $table): void {
            $table->boolean('ema_trend_filter_enabled_snapshot')->nullable()->after('maximum_coin_price_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('scan_runs', function (Blueprint $table): void {
            $table->dropColumn('ema_trend_filter_enabled_snapshot');
        });

        Schema::table('trading_strategies', function (Blueprint $table): void {
            $table->dropColumn('ema_trend_filter_enabled');
        });
    }
};
