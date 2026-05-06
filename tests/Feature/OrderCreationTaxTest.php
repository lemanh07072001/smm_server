<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Provider;
use App\Models\ProviderService;
use App\Models\Service;
use App\Models\User;
use App\Services\OrderCreationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderCreationTaxTest extends TestCase
{
    use RefreshDatabase;

    private function makeServiceAndUser(int $role, ?float $taxPercent = null): array
    {
        $provider = Provider::factory()->create(['is_active' => true]);
        $ps = ProviderService::factory()->create([
            'provider_id' => $provider->id,
            'cost_rate' => 50,
        ]);
        $service = Service::factory()->create([
            'provider_service_id' => $ps->id,
            'is_active' => true,
            'sell_rate' => 100,
            'min_quantity' => 1,
            'max_quantity' => 10000,
        ]);
        $user = User::factory()->create([
            'role' => $role,
            'tax_percent' => $taxPercent,
            'balance' => 10000000,
        ]);
        return [$service->fresh(['providerService.provider']), $user];
    }

    public function test_tax_user_charge_includes_tax(): void
    {
        [$service, $user] = $this->makeServiceAndUser(User::ROLE_TAX, 10);
        $svc = app(OrderCreationService::class);
        $amounts = $svc->calculateAmounts($service, $user, 1000);

        $this->assertEquals(110000, (float) $amounts['chargeAmount']);
        $this->assertEquals(10, (float) $amounts['taxPercent']);
    }

    public function test_normal_user_charge_no_tax(): void
    {
        [$service, $user] = $this->makeServiceAndUser(User::ROLE_USER);
        $amounts = app(OrderCreationService::class)->calculateAmounts($service, $user, 1000);

        $this->assertEquals(100000, (float) $amounts['chargeAmount']);
        $this->assertNull($amounts['taxPercent']);
    }

    public function test_order_record_snapshots_tax_percent(): void
    {
        [$service, $user] = $this->makeServiceAndUser(User::ROLE_TAX, 12.5);
        $svc = app(OrderCreationService::class);
        $amounts = $svc->calculateAmounts($service, $user, 100);

        $order = $svc->createOrderRecord($user, $service, 'https://x', 100, $amounts);

        $this->assertSame('12.50', (string) $order->fresh()->tax_percent);
        $this->assertEquals(11250, (float) $order->charge_amount);
    }

    public function test_normal_user_order_has_null_tax_percent(): void
    {
        [$service, $user] = $this->makeServiceAndUser(User::ROLE_USER);
        $svc = app(OrderCreationService::class);
        $amounts = $svc->calculateAmounts($service, $user, 100);
        $order = $svc->createOrderRecord($user, $service, 'https://x', 100, $amounts);

        $this->assertNull($order->fresh()->tax_percent);
    }
}
