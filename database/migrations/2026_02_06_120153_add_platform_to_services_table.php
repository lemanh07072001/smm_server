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
        Schema::table('services', function (Blueprint $table) {
            // Thêm cột platform sau cột group_id
            // Platform code: '1' = Facebook, '2' = Tiktok, '3' = Twitter, '4' = Instagram, '5' = Youtube, '6' = Zalo
            $table->string('platform', 10)->nullable()->after('group_id')->comment('Platform code from Service::PLATFORM');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('platform');
        });
    }
};
