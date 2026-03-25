<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_order_daily', function (Blueprint $table) {
            $table->dropUnique(['report_key']);
            $table->dropColumn('report_key');
            $table->unique(['user_id', 'service_id', 'date_at']);
        });
    }

    public function down(): void
    {
        Schema::table('report_order_daily', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'service_id', 'date_at']);
            $table->string('report_key', 64)->unique()->nullable();
        });
    }
};
