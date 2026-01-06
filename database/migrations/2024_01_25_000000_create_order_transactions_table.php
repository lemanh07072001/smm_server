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
        Schema::create('order_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('service_id');
            $table->enum('type', ['charge', 'refund'])->default('charge'); // charge = trừ tiền, refund = hoàn tiền
            $table->decimal('amount', 18, 2);           // Số tiền (luôn dương)
            $table->decimal('balance_before', 18, 2);   // Số dư trước giao dịch
            $table->decimal('balance_after', 18, 2);    // Số dư sau giao dịch
            $table->integer('quantity');                // Số lượng mua
            $table->decimal('rate', 18, 2);             // Giá bán (sell_rate)
            $table->decimal('cost_rate', 18, 2);        // Giá gốc (cost_rate)
            $table->decimal('profit', 18, 2)->default(0); // Lợi nhuận
            $table->string('service_name', 255);        // Tên dịch vụ (snapshot)
            $table->string('link', 1000)->nullable();   // Link đơn hàng
            $table->text('note')->nullable();           // Ghi chú
            $table->timestamps();

            // Foreign keys
            $table->foreign('order_id')->references('id')->on('orders')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('service_id')->references('id')->on('services')->onDelete('cascade');

            // Indexes cho thống kê
            $table->index('user_id');
            $table->index('service_id');
            $table->index('type');
            $table->index('created_at');
            $table->index(['user_id', 'type']);
            $table->index(['service_id', 'type']);
            $table->index(['created_at', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_transactions');
    }
};
