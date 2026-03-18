<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_auto', function (Blueprint $table) {
            $table->boolean('is_processed')->default(false)->after('expires_at')
                ->comment('Đánh dấu đã xử lý webhook, tránh dedup qua exception');
        });
    }

    public function down(): void
    {
        Schema::table('bank_auto', function (Blueprint $table) {
            $table->dropColumn('is_processed');
        });
    }
};
