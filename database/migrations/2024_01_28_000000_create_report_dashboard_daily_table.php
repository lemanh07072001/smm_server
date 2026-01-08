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
        Schema::create('report_dashboard_daily', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('date_at')->unique();   // Ngày thống kê (YYYYMMDD)

            // Thống kê đơn hàng
            $table->integer('total_orders')->default(0);           // Tổng số đơn hàng
            $table->integer('order_pending')->default(0);          // Đơn chờ xử lý
            $table->integer('order_processing')->default(0);       // Đang xử lý
            $table->integer('order_in_progress')->default(0);      // Đang chạy
            $table->integer('order_completed')->default(0);        // Hoàn thành
            $table->integer('order_partial')->default(0);          // Hoàn thành một phần
            $table->integer('order_canceled')->default(0);         // Đã hủy
            $table->integer('order_refunded')->default(0);         // Đã hoàn tiền
            $table->integer('order_failed')->default(0);           // Thất bại

            // Thống kê tài chính
            $table->decimal('total_revenue', 18, 2)->default(0);   // Tổng doanh thu (deposit)
            $table->decimal('total_charge', 18, 2)->default(0);    // Tổng tiền thu từ đơn hàng
            $table->decimal('total_cost', 18, 2)->default(0);      // Tổng tiền chi (cho provider)
            $table->decimal('total_profit', 18, 2)->default(0);    // Tổng lợi nhuận
            $table->decimal('total_refund', 18, 2)->default(0);    // Tổng tiền hoàn

            // Thống kê khách hàng
            $table->integer('total_customers')->default(0);        // Tổng số khách hàng có đơn
            $table->integer('new_customers')->default(0);          // Khách hàng mới (đăng ký trong ngày)
            $table->integer('active_customers')->default(0);       // Khách hàng hoạt động (có giao dịch)

            // Thống kê giao dịch
            $table->integer('total_deposits')->default(0);         // Số lần nạp tiền
            $table->decimal('deposit_amount', 18, 2)->default(0);  // Tổng tiền nạp

            $table->timestamps();

            $table->index('date_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('report_dashboard_daily');
    }
};
