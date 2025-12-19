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
        // Update services table
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('platform_id');
        });

        Schema::table('services', function (Blueprint $table) {
            $table->decimal('sell_rate', 18, 2)->change();
        });

        // Update provider_services table
        Schema::table('provider_services', function (Blueprint $table) {
            $table->decimal('cost_rate', 18, 2)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->unsignedBigInteger('platform_id')->nullable()->after('category_id');
            $table->decimal('sell_rate', 18, 6)->change();
        });

        Schema::table('provider_services', function (Blueprint $table) {
            $table->decimal('cost_rate', 18, 6)->change();
        });
    }
};
