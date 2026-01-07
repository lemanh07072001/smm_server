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
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('scanned_at')->nullable()->after('updated_at');
            $table->string('old_scanned_status', 20)->nullable()->after('scanned_at'); // Lưu status lần scan trước
            $table->index(['updated_at', 'scanned_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['updated_at', 'scanned_at']);
            $table->dropColumn(['scanned_at', 'old_scanned_status']);
        });
    }
};
