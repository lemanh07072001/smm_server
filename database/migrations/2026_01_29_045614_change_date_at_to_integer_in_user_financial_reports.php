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
            // Đổi cột date_at từ date sang integer (timestamp)
            $table->integer('date_at')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_financial_reports', function (Blueprint $table) {
            // Đổi lại từ integer về date
            $table->date('date_at')->change();
        });
    }
};
