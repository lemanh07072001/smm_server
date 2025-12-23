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
        Schema::table('dongtien', function (Blueprint $table) {
            // Đổi tên các cột
            $table->renameColumn('sotientruoc', 'balance_before');
            $table->renameColumn('sotienthaydoi', 'amount');
            $table->renameColumn('sotiensau', 'balance_after');
            $table->renameColumn('type', 'payment_method');
        });

        Schema::table('dongtien', function (Blueprint $table) {
            // Thêm các cột mới
            $table->enum('type', ['deposit', 'charge', 'refund', 'adjustment'])->default('deposit')->after('balance_after');
            $table->string('payment_ref', 255)->nullable()->after('type')->comment('Mã giao dịch payment gateway');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('dongtien', function (Blueprint $table) {
            // Xóa các cột mới
            $table->dropColumn(['type', 'payment_ref']);
        });

        Schema::table('dongtien', function (Blueprint $table) {
            // Đổi tên ngược lại
            $table->renameColumn('balance_before', 'sotientruoc');
            $table->renameColumn('amount', 'sotienthaydoi');
            $table->renameColumn('balance_after', 'sotiensau');
            $table->renameColumn('payment_method', 'type');
        });
    }
};
