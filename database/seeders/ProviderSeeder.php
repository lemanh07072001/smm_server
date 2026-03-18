<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ProviderSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $providers = [
            [
                'name' => 'Provider Example',
                'code' => 'provider_example',
                'api_url' => 'https://api.example.com',
                'api_key' => 'your_api_key_here',
                'balance' => 0,
                'balance_updated_at' => null,
                'is_active' => 1,
                'notes' => 'Đối tác mẫu',
                'image' => null,
            ],
            [
                'name' => 'OMO Provider',
                'code' => 'omo',
                'api_url' => 'https://tiktok.lovanthao.com',
                'api_key' => '7A3561DFCC723A02893FCDE7518738F8BC99B220',
                'balance' => 0,
                'balance_updated_at' => null,
                'is_active' => 1,
                'notes' => 'Đối tác OMO - API endpoint: /api/order, sử dụng KeyApi trong body và JSON format',
                'image' => null,
            ],
        ];

        foreach ($providers as $provider) {
            DB::table('providers')->updateOrInsert(
                ['code' => $provider['code']],
                [
                    'name' => $provider['name'],
                    'api_url' => $provider['api_url'],
                    'api_key' => $provider['api_key'],
                    'balance' => $provider['balance'],
                    'balance_updated_at' => $provider['balance_updated_at'],
                    'is_active' => $provider['is_active'],
                    'notes' => $provider['notes'],
                    'image' => $provider['image'],
                    'updated_at' => now(),
                ]
            );
        }
    }
}
