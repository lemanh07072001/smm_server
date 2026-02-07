<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            // General
            ['key' => 'site_name', 'value' => 'SMM Server', 'group' => 'general'],
            ['key' => 'site_description', 'value' => 'Dịch vụ mạng xã hội', 'group' => 'general'],
            ['key' => 'site_phone', 'value' => null, 'group' => 'general'],
            ['key' => 'site_email', 'value' => null, 'group' => 'general'],
            ['key' => 'logo_desktop', 'value' => null, 'group' => 'general'],
            ['key' => 'logo_mobile', 'value' => null, 'group' => 'general'],

            // Social
            ['key' => 'social_zalo', 'value' => null, 'group' => 'social'],
            ['key' => 'social_facebook', 'value' => null, 'group' => 'social'],
            ['key' => 'social_telegram', 'value' => null, 'group' => 'social'],
        ];

        foreach ($settings as $setting) {
            Setting::updateOrCreate(
                ['key' => $setting['key']],
                $setting
            );
        }
    }
}
