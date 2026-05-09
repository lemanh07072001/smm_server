<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE `orders` MODIFY COLUMN `status` ENUM(
            'pending',
            'processing',
            'in_progress',
            'completed',
            'partial',
            'canceled',
            'refunded',
            'refilling',
            'before_complete',
            'failed',
            'scheduled'
        ) NOT NULL DEFAULT 'pending'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE `orders` MODIFY COLUMN `status` ENUM(
            'pending',
            'processing',
            'in_progress',
            'completed',
            'partial',
            'canceled',
            'refunded',
            'failed'
        ) NOT NULL DEFAULT 'pending'");
    }
};
