<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reseller_provider_permissions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->comment('Reseller user ID (role=2)');
            $table->unsignedBigInteger('provider_id')->comment('Provider ID được phép dùng');
            $table->boolean('is_allowed')->default(true);
            $table->timestamps();

            $table->unique(['user_id', 'provider_id']);
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('provider_id')->references('id')->on('providers')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reseller_provider_permissions');
    }
};
