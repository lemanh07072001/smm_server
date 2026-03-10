<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_providers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique()->comment('Khớp với providers.code');
            $table->string('name', 100);
            $table->string('description', 255)->nullable();
            $table->boolean('is_allowed')->default(true)->comment('Superadmin cho phép hay không');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_providers');
    }
};
