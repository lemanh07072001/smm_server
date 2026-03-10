<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_auto', function (Blueprint $table) {
            $table->string('transaction_code')->nullable()->after('tid')->comment('Mã giao dịch parse từ nội dung chuyển khoản');
        });
    }

    public function down(): void
    {
        Schema::table('bank_auto', function (Blueprint $table) {
            $table->dropColumn('transaction_code');
        });
    }
};
