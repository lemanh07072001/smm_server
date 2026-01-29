<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('user_financial_reports', function (Blueprint $table) {
            // Xóa unique constraint cũ trên user_id
            $table->dropUnique(['user_id']);

            // Thêm cột date_at nullable trước
            $table->date('date_at')->nullable()->after('user_id')->comment('Ngày thống kê');
        });

        // Cập nhật giá trị mặc định cho các records hiện có (nếu có)
        DB::table('user_financial_reports')
            ->whereNull('date_at')
            ->update(['date_at' => date('Y-m-d')]);

        Schema::table('user_financial_reports', function (Blueprint $table) {
            // Đổi thành NOT NULL
            $table->date('date_at')->nullable(false)->change();

            // Tạo unique constraint mới trên user_id + date_at
            $table->unique(['user_id', 'date_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_financial_reports', function (Blueprint $table) {
            // Xóa unique constraint user_id + date_at
            $table->dropUnique(['user_id', 'date_at']);

            // Xóa cột date_at
            $table->dropColumn('date_at');

            // Khôi phục unique constraint cũ trên user_id
            $table->unique('user_id');
        });
    }
};
