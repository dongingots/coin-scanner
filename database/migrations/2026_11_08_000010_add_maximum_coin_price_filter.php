<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trading_strategies', function (Blueprint $table): void {
            $table->decimal('maximum_coin_price', 32, 8)->default(10.00000000);
        });

        Schema::table('scan_runs', function (Blueprint $table): void {
            $table->decimal('maximum_coin_price_snapshot', 32, 8)->nullable()->after('minimum_turnover_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('scan_runs', function (Blueprint $table): void {
            $table->dropColumn('maximum_coin_price_snapshot');
        });

        Schema::table('trading_strategies', function (Blueprint $table): void {
            $table->dropColumn('maximum_coin_price');
        });
    }
};
