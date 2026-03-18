<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_auto', function (Blueprint $table) {
            $table->string('payment_channel')->nullable()->after('deposit_type')
                ->comment('Kênh thanh toán: bank, momo, zalopay, vnpay, binance, ...');
        });
    }

    public function down(): void
    {
        Schema::table('bank_auto', function (Blueprint $table) {
            $table->dropColumn('payment_channel');
        });
    }
};
