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
            ['key' => 'site_logo', 'value' => null, 'group' => 'general'],

            // Contact
            ['key' => 'email', 'value' => null, 'group' => 'contact'],
            ['key' => 'phone', 'value' => null, 'group' => 'contact'],
            ['key' => 'address', 'value' => null, 'group' => 'contact'],

            // Social
            ['key' => 'zalo_link', 'value' => null, 'group' => 'social'],
            ['key' => 'facebook_link', 'value' => null, 'group' => 'social'],
            ['key' => 'telegram_link', 'value' => null, 'group' => 'social'],
            ['key' => 'youtube_link', 'value' => null, 'group' => 'social'],
            ['key' => 'tiktok_link', 'value' => null, 'group' => 'social'],
        ];

        foreach ($settings as $setting) {
            Setting::updateOrCreate(
                ['key' => $setting['key']],
                $setting
            );
        }
    }
}
