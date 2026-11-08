<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scan_run_pair_results', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('scan_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('scanned_pair_id')->constrained()->cascadeOnDelete();
            $table->foreignId('indicator_signal_id')->nullable()->constrained('trading_signals')->nullOnDelete();
            $table->decimal('turnover_24h_snapshot', 32, 8)->nullable();
            $table->decimal('minimum_turnover_snapshot', 32, 8)->nullable();
            $table->string('status', 16)->default('pending');
            $table->boolean('is_qualified')->nullable();
            $table->text('evaluation_reason')->nullable();
            $table->timestamp('evaluated_at')->nullable();
            $table->timestamps();

            $table->unique(['scan_run_id', 'scanned_pair_id'], 'scan_run_pair_results_run_pair_unique');
            $table->index(['scan_run_id', 'status', 'is_qualified'], 'scan_run_pair_results_summary_index');
            $table->index(['scanned_pair_id', 'scan_run_id'], 'scan_run_pair_results_pair_run_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scan_run_pair_results');
    }
};
