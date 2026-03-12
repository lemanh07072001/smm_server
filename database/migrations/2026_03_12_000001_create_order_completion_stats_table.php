<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_completion_stats', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id')->unique();
            $table->unsignedBigInteger('service_id');
            $table->integer('quantity');
            $table->integer('completion_minutes'); // thời gian hoàn thành tính bằng phút
            $table->timestamp('completed_at');
            $table->timestamps();

            $table->index('completed_at');
            $table->index('quantity');
            $table->index('service_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_completion_stats');
    }
};
