<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_auto', function (Blueprint $table) {
            $table->timestamp('processed_at')->nullable()->after('is_processed')
                ->comment('Thời điểm giao dịch được xử lý thành công');
        });
    }

    public function down(): void
    {
        Schema::table('bank_auto', function (Blueprint $table) {
            $table->dropColumn('processed_at');
        });
    }
};
