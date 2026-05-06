<?php

namespace Database\Factories;

use App\Models\Provider;
use App\Models\ProviderService;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ProviderService>
 */
class ProviderServiceFactory extends Factory
{
    protected $model = ProviderService::class;

    public function definition(): array
    {
        return [
            'provider_id' => Provider::factory(),
            'provider_service_code' => fake()->unique()->numerify('ps_######'),
            'name' => fake()->words(3, true),
            'category_name' => 'general',
            'cost_rate' => 50,
            'min_quantity' => 1,
            'max_quantity' => 10000,
            'is_active' => true,
        ];
    }
}
