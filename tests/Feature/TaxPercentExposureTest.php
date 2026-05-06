<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaxPercentExposureTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_response_includes_tax_percent(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $tax = User::factory()->create(['role' => User::ROLE_TAX, 'tax_percent' => 10]);

        $this->actingAs($admin)
            ->getJson("/api/users/{$tax->id}")
            ->assertOk()
            ->assertJsonPath('data.tax_percent', '10.00');
    }
}
