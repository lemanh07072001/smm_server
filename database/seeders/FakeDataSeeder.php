<?php

namespace Database\Seeders;

use App\Models\Order;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class FakeDataSeeder extends Seeder
{
    public function run(): void
    {
        // 1) Admin
        User::updateOrCreate(
            ['email' => 'admin@smm.local'],
            [
                'name' => 'Admin',
                'password' => Hash::make('password'),
                'role' => 0,
                'balance' => 10000000,
                'is_active' => 1,
                'api_key' => Str::random(40),
            ]
        );

        // 2) Demo customer
        $demo = User::updateOrCreate(
            ['email' => 'user@smm.local'],
            [
                'name' => 'Demo User',
                'password' => Hash::make('password'),
                'role' => 1,
                'balance' => 500000,
                'is_active' => 1,
                'api_key' => Str::random(40),
            ]
        );

        // 3) 30 fake users via factory
        if (User::count() < 30) {
            User::factory()->count(30)->create([
                'role' => 1,
                'is_active' => 1,
                'balance' => fake()->numberBetween(0, 2000000),
            ]);
        }

        // 4) Fake orders for demo user (need at least 1 service)
        $services = Service::limit(10)->get();
        if ($services->isEmpty()) {
            $this->command->warn('Không có service. Bỏ qua orders.');
            return;
        }

        $statuses = ['pending', 'processing', 'completed', 'partial', 'canceled'];
        $users = User::where('role', 1)->limit(20)->get();

        for ($i = 0; $i < 100; $i++) {
            $user = $users->random();
            $svc = $services->random();
            $qty = fake()->numberBetween(100, 5000);
            $sell = (float) ($svc->sell_rate ?? 1);
            $cost = (float) ($svc->cost_rate ?? 0.5);
            $charge = round($qty * $sell / 1000, 2);
            $costAmt = round($qty * $cost / 1000, 2);

            Order::create([
                'user_id' => $user->id,
                'order_source' => 'web',
                'service_id' => $svc->id,
                'provider_service_id' => $svc->provider_service_id ?? 0,
                'link' => 'https://example.com/post/' . Str::random(8),
                'quantity' => $qty,
                'start_count' => fake()->numberBetween(0, 1000),
                'remains' => 0,
                'status' => fake()->randomElement($statuses),
                'cost_rate' => $cost,
                'sell_rate' => $sell,
                'charge_amount' => $charge,
                'cost_amount' => $costAmt,
                'profit_amount' => $charge - $costAmt,
                'final_charge' => $charge,
                'final_cost' => $costAmt,
                'final_profit' => $charge - $costAmt,
                'is_finalized' => fake()->boolean(50),
                'created_at' => fake()->dateTimeBetween('-30 days', 'now'),
            ]);
        }

        $this->command->info('Đã tạo fake users + 100 orders.');
    }
}
