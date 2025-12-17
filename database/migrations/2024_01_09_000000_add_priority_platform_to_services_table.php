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
            $table->integer('priority')->default(0)->after('sort_order');
            $table->unsignedBigInteger('platform_id')->nullable()->after('category_id');
            $table->unsignedBigInteger('group_id')->nullable()->after('platform_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn(['priority', 'platform_id', 'group_id']);
        });
    }
};
