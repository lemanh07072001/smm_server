<?php

use Illuminate\Database\Migrations\Migration;
use MongoDB\Laravel\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'mongodb';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::connection('mongodb')->table('order_activity_logs', function (Blueprint $collection) {
            // Index cho order_id - truy vấn theo đơn hàng
            $collection->index('order_id');

            // Index cho user_id - truy vấn theo user
            $collection->index('user_id');

            // TTL index - tự động xóa logs sau 30 ngày
            $collection->index(
                ['created_at' => 1],
                'created_at_ttl',
                ['expireAfterSeconds' => 30 * 24 * 60 * 60]
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('mongodb')->table('order_activity_logs', function (Blueprint $collection) {
            $collection->dropIndex('order_id_1');
            $collection->dropIndex('user_id_1');
            $collection->dropIndex('order_id_1_created_at_1');
            $collection->dropIndex('provider_order_id_1');
            $collection->dropIndex('type_1');
            $collection->dropIndex('level_1');
            $collection->dropIndex('created_at_ttl');
        });
    }
};
