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
        Schema::create('user_financial_reports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->decimal('total_deposit', 18, 2)->default(0)->comment('Tổng tiền nạp');
            $table->decimal('total_spending', 18, 2)->default(0)->comment('Tổng tiền tiêu (charge)');
            $table->decimal('total_refund', 18, 2)->default(0)->comment('Tổng tiền hoàn');
            $table->decimal('current_balance', 18, 2)->default(0)->comment('Số dư hiện tại');
            $table->integer('total_orders')->default(0)->comment('Tổng số đơn hàng');
            $table->integer('completed_orders')->default(0)->comment('Số đơn hoàn thành');
            $table->timestamps();

            // Foreign key
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');

            // Index
            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_financial_reports');
    }
};
