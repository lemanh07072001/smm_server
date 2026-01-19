<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE dongtien MODIFY COLUMN type ENUM('deposit', 'charge', 'refund', 'adjustment', 'withdraw') DEFAULT 'deposit'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("ALTER TABLE dongtien MODIFY COLUMN type ENUM('deposit', 'charge', 'refund', 'adjustment') DEFAULT 'deposit'");
    }
};
