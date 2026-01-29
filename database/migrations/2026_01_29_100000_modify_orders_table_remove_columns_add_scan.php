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
        Schema::table('orders', function (Blueprint $table) {
            // Thêm index cho cột scan nếu chưa có
            if (!Schema::hasIndex('orders', 'orders_scan_index')) {
                $table->index('scan');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Xóa index scan nếu tồn tại
            if (Schema::hasIndex('orders', 'orders_scan_index')) {
                $table->dropIndex(['scan']);
            }
        });
    }
};
