<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scan_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('trading_strategy_id')->constrained()->cascadeOnDelete();
            $table->string('status', 32)->default('running');
            $table->unsignedInteger('instrument_count')->default(0);
            $table->unsignedInteger('pair_count')->default(0);
            $table->unsignedInteger('evaluated_count')->default(0);
            $table->unsignedInteger('qualified_count')->default(0);
            $table->unsignedInteger('not_qualified_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['trading_strategy_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scan_runs');
    }
};
