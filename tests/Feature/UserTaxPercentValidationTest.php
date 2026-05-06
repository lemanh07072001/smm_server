<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserTaxPercentValidationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    public function test_create_tax_user_with_default_percent(): void
    {
        $res = $this->actingAs($this->admin())->postJson('/api/users', [
            'name' => 'Tax A', 'email' => 'tax_a@x.com', 'password' => 'secret123',
            'role' => User::ROLE_TAX,
        ]);
        $res->assertCreated();
        $this->assertSame('10.00', (string) User::where('email', 'tax_a@x.com')->first()->tax_percent);
    }

    public function test_create_tax_user_with_custom_percent(): void
    {
        $res = $this->actingAs($this->admin())->postJson('/api/users', [
            'name' => 'Tax B', 'email' => 'tax_b@x.com', 'password' => 'secret123',
            'role' => User::ROLE_TAX, 'tax_percent' => 7.5,
        ]);
        $res->assertCreated();
        $this->assertSame('7.50', (string) User::where('email', 'tax_b@x.com')->first()->tax_percent);
    }

    public function test_create_tax_user_rejects_negative_percent(): void
    {
        $this->actingAs($this->admin())->postJson('/api/users', [
            'name' => 'Tax C', 'email' => 'tax_c@x.com', 'password' => 'secret123',
            'role' => User::ROLE_TAX, 'tax_percent' => -1,
        ])->assertStatus(422)->assertJsonValidationErrors(['tax_percent']);
    }

    public function test_create_tax_user_rejects_percent_over_100(): void
    {
        $this->actingAs($this->admin())->postJson('/api/users', [
            'name' => 'Tax D', 'email' => 'tax_d@x.com', 'password' => 'secret123',
            'role' => User::ROLE_TAX, 'tax_percent' => 150,
        ])->assertStatus(422)->assertJsonValidationErrors(['tax_percent']);
    }

    public function test_non_tax_role_with_tax_percent_clears_to_null(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_TAX, 'tax_percent' => 10,
        ]);
        $this->actingAs($this->admin())->postJson("/api/users/{$user->id}", [
            'role' => User::ROLE_USER,
        ])->assertOk();
        $this->assertNull($user->fresh()->tax_percent);
    }
}
