<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_revenue_user_dailies', function (Blueprint $table) {
            $table->id();
            $table->date('date_at');
            $table->unsignedBigInteger('user_id');

            // price_system cộng dồn theo trạng thái
            $table->decimal('system_complete', 16, 2)->default(0);
            $table->decimal('system_waiting_check', 16, 2)->default(0);
            $table->decimal('system_approved', 16, 2)->default(0);
            $table->decimal('system_rejected', 16, 2)->default(0);
            $table->decimal('system_expired', 16, 2)->default(0);

            $table->timestamps();

            $table->unique(['date_at', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_revenue_user_dailies');
    }
};
