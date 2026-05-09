<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dateTime('scheduled_at')->nullable()->after('number_per_date');
            $table->index(['status', 'scheduled_at'], 'orders_status_scheduled_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_status_scheduled_at_index');
            $table->dropColumn('scheduled_at');
        });
    }
};
