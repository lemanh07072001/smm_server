<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_order_dailies', function (Blueprint $table) {
            $table->id();
            $table->date('date_at');
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('user_id');

            // Đếm job theo trạng thái
            $table->unsignedInteger('job_pending')->default(0);
            $table->unsignedInteger('job_complete')->default(0);
            $table->unsignedInteger('job_error')->default(0);
            $table->unsignedInteger('job_reject')->default(0);
            $table->unsignedInteger('job_approved')->default(0);
            $table->unsignedInteger('job_expired')->default(0);
            $table->unsignedInteger('job_waiting_check')->default(0);

            // Tiền theo trạng thái có liên quan
            $table->decimal('pending_buyer', 16, 2)->default(0);      // complete → chờ duyệt
            $table->decimal('pending_system', 16, 2)->default(0);
            $table->decimal('approved_buyer', 16, 2)->default(0);     // approved → đã giải ngân
            $table->decimal('approved_system', 16, 2)->default(0);
            $table->decimal('rejected_buyer', 16, 2)->default(0);     // reject → bị từ chối
            $table->decimal('rejected_system', 16, 2)->default(0);
            $table->decimal('waiting_buyer', 16, 2)->default(0);      // waiting_check → chờ kiểm tra
            $table->decimal('waiting_system', 16, 2)->default(0);

            $table->timestamps();

            $table->unique(['date_at', 'order_id', 'user_id']);
            $table->index('user_id');
            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_order_dailies');
    }
};
