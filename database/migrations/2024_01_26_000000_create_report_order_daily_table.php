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
        Schema::create('report_order_daily', function (Blueprint $table) {
            $table->id();
            $table->string('report_key', 64)->unique();     // MD5 key để update
            $table->unsignedInteger('date_at');              // Ngày thống kê (YYYYMMDD)
            $table->unsignedBigInteger('user_id');           // User ID
            $table->unsignedBigInteger('service_id');        // Service ID

            // Thống kê số lượng đơn hàng
            $table->integer('order_pending')->default(0);    // Đơn chờ xử lý
            $table->integer('order_processing')->default(0); // Đang xử lý
            $table->integer('order_in_progress')->default(0);// Đang chạy
            $table->integer('order_completed')->default(0);  // Hoàn thành
            $table->integer('order_partial')->default(0);    // Hoàn thành một phần
            $table->integer('order_canceled')->default(0);   // Đã hủy
            $table->integer('order_refunded')->default(0);   // Đã hoàn tiền
            $table->integer('order_failed')->default(0);     // Thất bại

            // Thống kê tài chính
            $table->decimal('total_charge', 18, 2)->default(0);   // Tổng tiền thu (từ user)
            $table->decimal('total_cost', 18, 2)->default(0);     // Tổng tiền chi (cho provider)
            $table->decimal('total_profit', 18, 2)->default(0);   // Tổng lợi nhuận
            $table->decimal('total_refund', 18, 2)->default(0);   // Tổng tiền hoàn

            // Thống kê số lượng
            $table->integer('total_quantity')->default(0);        // Tổng số lượng mua

            $table->timestamps();

            // Indexes
            $table->index('date_at');
            $table->index('user_id');
            $table->index('service_id');
            $table->index(['date_at', 'user_id']);
            $table->index(['date_at', 'service_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('report_order_daily');
    }
};
