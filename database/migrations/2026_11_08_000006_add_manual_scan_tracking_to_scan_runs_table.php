<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scan_runs', function (Blueprint $table): void {
            $table->timestamp('queued_at')->nullable()->after('status');
            $table->timestamp('candle_at')->nullable()->after('started_at');
        });
    }

    public function down(): void
    {
        Schema::table('scan_runs', function (Blueprint $table): void {
            $table->dropColumn(['queued_at', 'candle_at']);
        });
    }
};
