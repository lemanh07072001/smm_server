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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('service_id');
            $table->unsignedBigInteger('provider_service_id');
            $table->string('provider_order_id', 100)->nullable();
            $table->string('link', 1000);
            $table->integer('quantity');
            $table->integer('start_count')->nullable();
            $table->integer('remains')->nullable();
            $table->enum('status', [
                'pending',
                'processing',
                'in_progress',
                'completed',
                'partial',
                'canceled',
                'refunded',
                'failed'
            ])->default('pending');
            $table->decimal('cost_rate', 18, 2);
            $table->decimal('sell_rate', 18, 2);
            $table->decimal('charge_amount', 18, 2);
            $table->decimal('cost_amount', 18, 2);
            $table->decimal('profit_amount', 18, 2);
            $table->decimal('refund_amount', 18, 2)->default(0);
            $table->decimal('final_charge', 18, 2)->default(0);
            $table->decimal('final_cost', 18, 2)->default(0);
            $table->decimal('final_profit', 18, 2)->default(0);
            $table->tinyInteger('is_finalized')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamps();

            // Foreign keys
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('service_id')->references('id')->on('services')->onDelete('cascade');
            $table->foreign('provider_service_id')->references('id')->on('provider_services')->onDelete('cascade');

            // Indexes
            $table->index('user_id');
            $table->index('service_id');
            $table->index('provider_service_id');
            $table->index('status');
            $table->index('provider_order_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};

