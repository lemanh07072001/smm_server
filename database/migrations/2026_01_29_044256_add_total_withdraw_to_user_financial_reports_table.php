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
            $table->decimal('total_withdraw', 18, 2)->default(0)->after('total_refund')->comment('Tổng tiền rút');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_financial_reports', function (Blueprint $table) {
            $table->dropColumn('total_withdraw');
        });
    }
};
