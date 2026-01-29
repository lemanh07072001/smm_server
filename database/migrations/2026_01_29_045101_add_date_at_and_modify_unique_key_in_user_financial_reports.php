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
            // Xóa unique constraint cũ trên user_id
            $table->dropUnique(['user_id']);

            // Thêm cột date_at
            $table->date('date_at')->after('user_id')->comment('Ngày thống kê');

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
