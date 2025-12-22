<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('bank_auto', function (Blueprint $table) {
            $table->id();
            $table->string('tid')->unique();
            $table->text('description');
            $table->string('date');
            $table->text('data');
            $table->integer('amount')->default(0);
            $table->unsignedInteger('user_id')->nullable();
            $table->enum('transaction_type', ['PLUS', 'MINUS'])->default('PLUS');
            $table->enum('type', ['bank', 'binance'])->default('bank');
            $table->string('status')->nullable();
            $table->text('note')->nullable();
            $table->string('deposit_type')->default('auto')->comment('Loại nạp tiền');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bank_auto');
    }
};

