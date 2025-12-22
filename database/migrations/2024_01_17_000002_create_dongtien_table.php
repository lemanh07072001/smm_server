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
        Schema::create('dongtien', function (Blueprint $table) {
            $table->id();
            $table->integer('sotientruoc');
            $table->integer('sotienthaydoi');
            $table->integer('sotiensau');
            $table->dateTime('thoigian');
            $table->string('noidung');
            $table->unsignedInteger('user_id')->nullable();
            $table->unsignedInteger('order_id')->nullable();
            $table->string('type')->nullable();
            $table->integer('scan')->default(0)->comment('Đã scan thống kê');
            $table->text('datas')->nullable();
            $table->integer('is_payment_affiliate')->default(0);
            $table->unsignedInteger('bank_auto_id')->nullable();
            $table->timestamps();

            // Indexes
            $table->index('user_id');
            $table->index('type', 'dongtien_type_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dongtien');
    }
};

