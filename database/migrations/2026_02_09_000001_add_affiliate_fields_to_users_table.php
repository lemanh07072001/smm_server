<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('referred_by')->nullable()->after('is_active');
            $table->decimal('affiliate_balance', 18, 6)->default(0)->after('referred_by');

            $table->index('referred_by');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['referred_by']);
            $table->dropColumn(['referred_by', 'affiliate_balance']);
        });
    }
};
