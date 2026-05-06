<?php

namespace Tests\Unit;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserRoleTaxTest extends TestCase
{
    use RefreshDatabase;

    public function test_role_tax_constant_is_four(): void
    {
        $this->assertSame(4, User::ROLE_TAX);
        $this->assertContains(4, User::ROLES);
    }

    public function test_is_tax_returns_true_for_role_4(): void
    {
        $user = User::factory()->make(['role' => User::ROLE_TAX, 'tax_percent' => 10]);
        $this->assertTrue($user->isTax());
    }

    public function test_is_tax_returns_false_for_other_roles(): void
    {
        $user = User::factory()->make(['role' => User::ROLE_USER]);
        $this->assertFalse($user->isTax());
    }

    public function test_tax_percent_is_cast_to_decimal_string(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_TAX, 'tax_percent' => 12.5]);
        $this->assertSame('12.50', (string) $user->fresh()->tax_percent);
    }
}
