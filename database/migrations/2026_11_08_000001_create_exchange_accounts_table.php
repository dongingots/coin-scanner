<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchange_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('environment', 16)->default('testnet');
            $table->text('api_key');
            $table->text('api_secret');
            $table->boolean('is_enabled')->default(false);
            $table->timestamps();

            $table->index(['user_id', 'environment']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_accounts');
    }
};
