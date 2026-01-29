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
            // Xóa các cột không cần thiết (chỉ xóa những cột tồn tại)
            if (Schema::hasColumn('orders', 'scanned_at')) {
                $table->dropColumn('scanned_at');
            }

            // Cột scan đã tồn tại, chỉ thêm index nếu chưa có
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

            // Khôi phục lại cột scanned_at
            if (!Schema::hasColumn('orders', 'scanned_at')) {
                $table->timestamp('scanned_at')->nullable()->after('updated_at');
            }
        });
    }
};
