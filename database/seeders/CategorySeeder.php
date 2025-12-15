<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $categories = [
            ['name' => 'Instagram', 'description' => 'Dịch vụ tăng tương tác Instagram'],
            ['name' => 'TikTok', 'description' => 'Dịch vụ tăng tương tác TikTok'],
            ['name' => 'Facebook', 'description' => 'Dịch vụ tăng tương tác Facebook'],
            ['name' => 'YouTube', 'description' => 'Dịch vụ tăng tương tác YouTube'],
            ['name' => 'Twitter', 'description' => 'Dịch vụ tăng tương tác Twitter'],
            ['name' => 'Telegram', 'description' => 'Dịch vụ tăng tương tác Telegram'],
            ['name' => 'Spotify', 'description' => 'Dịch vụ tăng tương tác Spotify'],
            ['name' => 'SoundCloud', 'description' => 'Dịch vụ tăng tương tác SoundCloud'],
            ['name' => 'LinkedIn', 'description' => 'Dịch vụ tăng tương tác LinkedIn'],
            ['name' => 'Pinterest', 'description' => 'Dịch vụ tăng tương tác Pinterest'],
            ['name' => 'Snapchat', 'description' => 'Dịch vụ tăng tương tác Snapchat'],
            ['name' => 'Discord', 'description' => 'Dịch vụ tăng tương tác Discord'],
            ['name' => 'Twitch', 'description' => 'Dịch vụ tăng tương tác Twitch'],
            ['name' => 'Reddit', 'description' => 'Dịch vụ tăng tương tác Reddit'],
            ['name' => 'Threads', 'description' => 'Dịch vụ tăng tương tác Threads'],
            ['name' => 'Instagram Followers', 'description' => 'Dịch vụ tăng followers Instagram'],
            ['name' => 'Instagram Likes', 'description' => 'Dịch vụ tăng likes Instagram'],
            ['name' => 'Instagram Views', 'description' => 'Dịch vụ tăng views Instagram'],
            ['name' => 'Instagram Comments', 'description' => 'Dịch vụ tăng comments Instagram'],
            ['name' => 'TikTok Followers', 'description' => 'Dịch vụ tăng followers TikTok'],
            ['name' => 'TikTok Likes', 'description' => 'Dịch vụ tăng likes TikTok'],
            ['name' => 'TikTok Views', 'description' => 'Dịch vụ tăng views TikTok'],
            ['name' => 'TikTok Shares', 'description' => 'Dịch vụ tăng shares TikTok'],
            ['name' => 'Facebook Likes', 'description' => 'Dịch vụ tăng likes Facebook'],
            ['name' => 'Facebook Followers', 'description' => 'Dịch vụ tăng followers Facebook'],
            ['name' => 'Facebook Views', 'description' => 'Dịch vụ tăng views Facebook'],
            ['name' => 'YouTube Views', 'description' => 'Dịch vụ tăng views YouTube'],
            ['name' => 'YouTube Subscribers', 'description' => 'Dịch vụ tăng subscribers YouTube'],
            ['name' => 'YouTube Likes', 'description' => 'Dịch vụ tăng likes YouTube'],
            ['name' => 'YouTube Comments', 'description' => 'Dịch vụ tăng comments YouTube'],
        ];

        foreach ($categories as $index => $category) {
            Category::create([
                'name' => $category['name'],
                'slug' => Str::slug($category['name']),
                'description' => $category['description'],
                'sort_order' => $index + 1,
                'is_active' => 1,
            ]);
        }
    }
}
