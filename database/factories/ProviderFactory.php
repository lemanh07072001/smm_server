<?php

namespace Database\Factories;

use App\Models\Provider;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Provider>
 */
class ProviderFactory extends Factory
{
    protected $model = Provider::class;

    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'code' => 'prov_' . fake()->unique()->numerify('######'),
            'api_url' => 'https://example.test/api',
            'api_key' => fake()->sha1(),
            'balance' => 0,
            'is_active' => true,
        ];
    }
}
