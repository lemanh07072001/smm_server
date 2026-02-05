<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CountrySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $countries = [
            [
                'name' => 'Việt Nam',
                'code' => 'VI',
                'status' => 1,
                'sort_order' => 1,
            ],
            [
                'name' => 'Thái Lan',
                'code' => 'TH',
                'status' => 1,
                'sort_order' => 2,
            ],
            [
                'name' => 'Other',
                'code' => 'Other',
                'status' => 1,
                'sort_order' => 3,
            ],
        ];

        foreach ($countries as $country) {
            DB::table('countries')->insert([
                'name' => $country['name'],
                'code' => $country['code'],
                'status' => $country['status'],
                'sort_order' => $country['sort_order'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
