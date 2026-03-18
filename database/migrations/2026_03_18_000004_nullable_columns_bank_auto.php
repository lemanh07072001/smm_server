<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_auto', function (Blueprint $table) {
            $table->string('tid')->nullable()->change();
            $table->string('date')->nullable()->change();
            $table->text('data')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('bank_auto', function (Blueprint $table) {
            $table->string('tid')->nullable(false)->change();
            $table->string('date')->nullable(false)->change();
            $table->text('data')->nullable(false)->change();
        });
    }
};
