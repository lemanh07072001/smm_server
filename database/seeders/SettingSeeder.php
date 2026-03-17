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
            ['key' => 'site_name',              'value' => 'SMM Server',           'group' => 'general'],
            ['key' => 'site_short_description', 'value' => null,                   'group' => 'general'],
            ['key' => 'site_description',       'value' => 'Dịch vụ mạng xã hội', 'group' => 'general'],
            ['key' => 'site_phone',             'value' => null,                   'group' => 'general'],
            ['key' => 'site_email',             'value' => null,                   'group' => 'general'],
            ['key' => 'sidebar_description',    'value' => null,                   'group' => 'general'],
            ['key' => 'footer_content',         'value' => null,                   'group' => 'general'],
            ['key' => 'support_contact',        'value' => null,                   'group' => 'general'],

            // Logo / Images
            ['key' => 'logo_desktop', 'value' => null, 'group' => 'logo'],
            ['key' => 'logo_mobile',  'value' => null, 'group' => 'logo'],
            ['key' => 'favicon',      'value' => null, 'group' => 'logo'],
            ['key' => 'og_image',     'value' => null, 'group' => 'logo'],

            // Colors
            ['key' => 'color_primary',   'value' => '#10B981', 'group' => 'colors'],
            ['key' => 'color_secondary', 'value' => '#3b82f6', 'group' => 'colors'],

            // SEO - per locale
            ['key' => 'seo_title_vi',       'value' => null,            'group' => 'seo'],
            ['key' => 'seo_description_vi', 'value' => null,            'group' => 'seo'],
            ['key' => 'seo_keywords_vi',    'value' => null,            'group' => 'seo'],
            ['key' => 'seo_title_en',       'value' => null,            'group' => 'seo'],
            ['key' => 'seo_description_en', 'value' => null,            'group' => 'seo'],
            ['key' => 'seo_keywords_en',    'value' => null,            'group' => 'seo'],
            ['key' => 'seo_title_th',       'value' => null,            'group' => 'seo'],
            ['key' => 'seo_description_th', 'value' => null,            'group' => 'seo'],
            ['key' => 'seo_keywords_th',    'value' => null,            'group' => 'seo'],
            ['key' => 'seo_robots',         'value' => 'index, follow', 'group' => 'seo'],

            // Advanced
            ['key' => 'custom_css',        'value' => null,     'group' => 'advanced'],
            ['key' => 'custom_js',         'value' => null,     'group' => 'advanced'],
            ['key' => 'maintenance_mode',  'value' => '0',      'group' => 'advanced'],
            ['key' => 'default_theme',     'value' => 'system', 'group' => 'advanced'],

            // Sidebar
            ['key' => 'sidebar_announcement', 'value' => null, 'group' => 'sidebar'],
            ['key' => 'sidebar_links',        'value' => null, 'group' => 'sidebar'],
            ['key' => 'sidebar_videos',       'value' => null, 'group' => 'sidebar'],

            // Bank
            ['key' => 'bank_name',              'value' => null, 'group' => 'bank'],
            ['key' => 'bank_code',              'value' => null, 'group' => 'bank'],
            ['key' => 'bank_account_number',    'value' => null, 'group' => 'bank'],
            ['key' => 'bank_account_holder',    'value' => null, 'group' => 'bank'],
            ['key' => 'pay2s_token',            'value' => null, 'group' => 'bank'],

            // Telegram
            ['key' => 'telegram_bot_token',       'value' => null, 'group' => 'telegram'],
            ['key' => 'telegram_chat_id_system',  'value' => null, 'group' => 'telegram'],

            // Social
            ['key' => 'social_zalo',     'value' => null, 'group' => 'social'],
            ['key' => 'social_facebook', 'value' => null, 'group' => 'social'],
            ['key' => 'social_telegram', 'value' => null, 'group' => 'social'],
            ['key' => 'social_youtube',  'value' => null, 'group' => 'social'],
        ];

        foreach ($settings as $setting) {
            Setting::updateOrCreate(
                ['key' => $setting['key']],
                $setting
            );
        }
    }
}
