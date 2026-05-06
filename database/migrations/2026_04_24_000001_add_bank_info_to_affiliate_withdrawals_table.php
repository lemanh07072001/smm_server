<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('affiliate_withdrawals', function (Blueprint $table) {
            $table->string('bank_name', 100)->nullable()->after('amount');
            $table->string('account_number', 50)->nullable()->after('bank_name');
            $table->string('account_holder', 100)->nullable()->after('account_number');
        });
    }

    public function down(): void
    {
        Schema::table('affiliate_withdrawals', function (Blueprint $table) {
            $table->dropColumn(['bank_name', 'account_number', 'account_holder']);
        });
    }
};
