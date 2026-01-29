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
            // Xóa các cột không cần thiết
            $table->dropColumn(['completed_at', 'scanned_at', 'old_scanned_status']);

            // Thêm cột scan với index
            $table->integer('scan')->default(0)->after('updated_at');
            $table->index('scan');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Xóa cột scan
            $table->dropIndex(['scan']);
            $table->dropColumn('scan');

            // Khôi phục lại các cột đã xóa
            $table->timestamp('completed_at')->nullable()->after('error_message');
            $table->timestamp('scanned_at')->nullable()->after('updated_at');
            $table->string('old_scanned_status', 20)->nullable()->after('scanned_at');
        });
    }
};
