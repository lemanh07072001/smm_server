<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_agent_prices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('service_id');
            $table->tinyInteger('agent_level');
            $table->decimal('sell_rate', 18, 2);
            $table->timestamps();

            $table->unique(['service_id', 'agent_level']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_agent_prices');
    }
};
