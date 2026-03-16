<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('affiliate_commissions', function (Blueprint $table) {
            // Làm order_id nullable (hoa hồng từ deposit không có order)
            $table->unsignedBigInteger('order_id')->nullable()->change();

            // Thêm deposit_id và source để phân biệt nguồn hoa hồng
            $table->unsignedBigInteger('deposit_id')->nullable()->after('order_id');
            $table->string('source', 20)->default('order')->after('deposit_id')->comment('order | deposit');

            // Unique để tránh double-commission trên cùng 1 deposit
            $table->unique('deposit_id', 'affiliate_commissions_deposit_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('affiliate_commissions', function (Blueprint $table) {
            $table->dropUnique('affiliate_commissions_deposit_id_unique');
            $table->dropColumn(['deposit_id', 'source']);
            $table->unsignedBigInteger('order_id')->nullable(false)->change();
        });
    }
};
