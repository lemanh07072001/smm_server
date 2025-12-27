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
        // Thêm reaction_types vào provider_services
        Schema::table('provider_services', function (Blueprint $table) {
            $table->json('reaction_types')->nullable()->after('is_active');
        });

        // Xóa reaction_types khỏi services
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('reaction_types');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Thêm lại reaction_types vào services
        Schema::table('services', function (Blueprint $table) {
            $table->json('reaction_types')->nullable()->after('allow_multiple_reactions');
        });

        // Xóa reaction_types khỏi provider_services
        Schema::table('provider_services', function (Blueprint $table) {
            $table->dropColumn('reaction_types');
        });
    }
};
