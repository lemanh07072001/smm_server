<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transaction_banks', function (Blueprint $table) {
            $table->id();
            $table->string('tid')->unique()->comment('Transaction ID từ cổng thanh toán');
            $table->string('source')->default('pay2s')->comment('Nguồn webhook: pay2s, sepay, macrodroid...');
            $table->enum('transfer_type', ['IN', 'OUT'])->default('IN')->comment('Loại giao dịch');
            $table->bigInteger('amount')->comment('Số tiền (VND)');
            $table->string('content')->nullable()->comment('Nội dung chuyển khoản');
            $table->string('account_number')->nullable()->comment('Số tài khoản ngân hàng');
            $table->string('bank_code')->nullable()->comment('Mã ngân hàng');
            $table->timestamp('transacted_at')->nullable()->comment('Thời điểm giao dịch tại ngân hàng');
            $table->boolean('is_processed')->default(false)->comment('Đã xử lý khớp với BankAuto chưa');
            $table->unsignedBigInteger('bank_auto_id')->nullable()->comment('BankAuto đã được match');
            $table->json('raw_data')->nullable()->comment('Raw payload từ webhook');
            $table->timestamps();

            $table->index(['is_processed', 'transfer_type']);
            $table->index('bank_auto_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_banks');
    }
};
