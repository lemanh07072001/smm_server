<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedTinyInteger('retry_count')->default(0)->after('error_message');
            $table->unsignedTinyInteger('is_priority')->default(1)->after('retry_count');

            $table->index('retry_count');
            $table->index('is_priority');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['retry_count']);
            $table->dropIndex(['is_priority']);
            $table->dropColumn(['retry_count', 'is_priority']);
        });
    }
};
