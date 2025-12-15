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
        Schema::table('users', function (Blueprint $table) {
            $table->decimal('balance', 18, 6)->default(0)->after('role');
            $table->decimal('discount', 5, 2)->default(0)->after('balance');
            $table->string('api_key', 64)->unique()->nullable()->after('discount');
            $table->tinyInteger('is_active')->default(1)->after('api_key');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['balance', 'discount', 'api_key', 'is_active']);
        });
    }
};
