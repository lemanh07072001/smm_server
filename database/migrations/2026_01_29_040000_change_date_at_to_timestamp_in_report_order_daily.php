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
        Schema::table('report_order_daily', function (Blueprint $table) {
            $table->unsignedBigInteger('date_at')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('report_order_daily', function (Blueprint $table) {
            $table->unsignedInteger('date_at')->change();
        });
    }
};
