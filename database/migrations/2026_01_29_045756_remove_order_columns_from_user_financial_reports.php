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
        Schema::table('user_financial_reports', function (Blueprint $table) {
            $table->dropColumn(['total_orders', 'completed_orders']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_financial_reports', function (Blueprint $table) {
            $table->integer('total_orders')->default(0)->comment('Tổng số đơn hàng');
            $table->integer('completed_orders')->default(0)->comment('Số đơn hoàn thành');
        });
    }
};
