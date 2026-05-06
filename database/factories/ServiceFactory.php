<?php

namespace Database\Factories;

use App\Models\ProviderService;
use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Service>
 */
class ServiceFactory extends Factory
{
    protected $model = Service::class;

    public function definition(): array
    {
        return [
            'provider_service_id' => ProviderService::factory(),
            'name' => fake()->words(3, true),
            'description' => fake()->sentence(),
            'sell_rate' => 100,
            'min_quantity' => 1,
            'max_quantity' => 10000,
            'sort_order' => 0,
            'is_active' => true,
        ];
    }
}
