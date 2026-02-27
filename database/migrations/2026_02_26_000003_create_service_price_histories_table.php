<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_price_histories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('service_id');
            $table->tinyInteger('agent_level')->nullable();
            $table->decimal('old_price', 18, 2);
            $table->decimal('new_price', 18, 2);
            $table->unsignedBigInteger('changed_by');
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('service_id')->references('id')->on('services')->onDelete('cascade');
            $table->foreign('changed_by')->references('id')->on('users');
            $table->index(['service_id', 'agent_level']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_price_histories');
    }
};
