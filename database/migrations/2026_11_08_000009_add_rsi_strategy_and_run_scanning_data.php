<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trading_strategies', function (Blueprint $table): void {
            $table->decimal('rsi_maximum', 5, 2)->default(65.00);
        });

        Schema::table('scan_runs', function (Blueprint $table): void {
            $table->decimal('rsi_maximum_snapshot', 5, 2)->nullable()->after('minimum_turnover_snapshot');
        });

        Schema::table('trading_signals', function (Blueprint $table): void {
            $table->decimal('rsi_14', 8, 4)->nullable()->after('volume_sma');
            $table->string('rsi_data_status', 32)->nullable()->after('rsi_14');
        });

        Schema::table('scan_run_pair_results', function (Blueprint $table): void {
            $table->string('rsi_status', 32)->default('not_evaluated')->after('minimum_turnover_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('scan_run_pair_results', function (Blueprint $table): void {
            $table->dropColumn('rsi_status');
        });

        Schema::table('trading_signals', function (Blueprint $table): void {
            $table->dropColumn(['rsi_14', 'rsi_data_status']);
        });

        Schema::table('scan_runs', function (Blueprint $table): void {
            $table->dropColumn('rsi_maximum_snapshot');
        });

        Schema::table('trading_strategies', function (Blueprint $table): void {
            $table->dropColumn('rsi_maximum');
        });
    }
};
