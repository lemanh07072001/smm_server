<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ServiceSeeder extends Seeder
{
    public function run(): void
    {
        // Lấy provider_id của provider_example (hoặc provider đầu tiên)
        $provider = DB::table('providers')->first();
        if (!$provider) {
            $this->command->warn('Chưa có provider nào. Chạy ProviderSeeder trước.');
            return;
        }
        $providerId = $provider->id;

        // Tạo category_groups nếu chưa có
        $groupDefs = [
            ['name' => 'Facebook',  'slug' => 'facebook',  'sort_order' => 1],
            ['name' => 'TikTok',    'slug' => 'tiktok',    'sort_order' => 2],
            ['name' => 'Instagram', 'slug' => 'instagram', 'sort_order' => 3],
            ['name' => 'YouTube',   'slug' => 'youtube',   'sort_order' => 4],
            ['name' => 'Twitter',   'slug' => 'twitter',   'sort_order' => 5],
        ];
        foreach ($groupDefs as $g) {
            DB::table('category_groups')->updateOrInsert(
                ['slug' => $g['slug']],
                ['name' => $g['name'], 'sort_order' => $g['sort_order'], 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]
            );
        }
        $categoryGroups = DB::table('category_groups')->pluck('id', 'name');
        $defaultGroupId = $categoryGroups->first();

        // ============================================================
        // 1. THÊM PROVIDER SERVICES (dịch vụ từ nhà cung cấp)
        // ============================================================
        $providerServices = [
            // Facebook
            ['code' => 'FB001', 'name' => 'Facebook Like Bài Viết - Việt Nam', 'category_name' => 'Facebook', 'cost_rate' => 5.00, 'min_quantity' => 100, 'max_quantity' => 50000],
            ['code' => 'FB002', 'name' => 'Facebook Follow - Việt Nam', 'category_name' => 'Facebook', 'cost_rate' => 8.00, 'min_quantity' => 100, 'max_quantity' => 10000],
            ['code' => 'FB003', 'name' => 'Facebook Like Page', 'category_name' => 'Facebook', 'cost_rate' => 10.00, 'min_quantity' => 100, 'max_quantity' => 20000],
            ['code' => 'FB004', 'name' => 'Facebook Reaction Phẫn Nộ', 'category_name' => 'Facebook', 'cost_rate' => 6.00, 'min_quantity' => 100, 'max_quantity' => 50000],
            ['code' => 'FB005', 'name' => 'Facebook Reaction Haha', 'category_name' => 'Facebook', 'cost_rate' => 6.00, 'min_quantity' => 100, 'max_quantity' => 50000],
            ['code' => 'FB006', 'name' => 'Facebook Share Bài Viết', 'category_name' => 'Facebook', 'cost_rate' => 15.00, 'min_quantity' => 50, 'max_quantity' => 10000],
            ['code' => 'FB007', 'name' => 'Facebook Comment Bài Viết', 'category_name' => 'Facebook', 'cost_rate' => 50.00, 'min_quantity' => 10, 'max_quantity' => 5000],
            ['code' => 'FB008', 'name' => 'Facebook Buff View Livestream', 'category_name' => 'Facebook', 'cost_rate' => 20.00, 'min_quantity' => 100, 'max_quantity' => 5000],
            ['code' => 'FB009', 'name' => 'Facebook Tăng Member Nhóm', 'category_name' => 'Facebook', 'cost_rate' => 12.00, 'min_quantity' => 100, 'max_quantity' => 10000],

            // TikTok
            ['code' => 'TK001', 'name' => 'TikTok Like Video', 'category_name' => 'TikTok', 'cost_rate' => 4.00, 'min_quantity' => 100, 'max_quantity' => 100000],
            ['code' => 'TK002', 'name' => 'TikTok Follow - Việt Nam', 'category_name' => 'TikTok', 'cost_rate' => 7.00, 'min_quantity' => 100, 'max_quantity' => 50000],
            ['code' => 'TK003', 'name' => 'TikTok View Video', 'category_name' => 'TikTok', 'cost_rate' => 1.50, 'min_quantity' => 1000, 'max_quantity' => 500000],
            ['code' => 'TK004', 'name' => 'TikTok Share Video', 'category_name' => 'TikTok', 'cost_rate' => 10.00, 'min_quantity' => 100, 'max_quantity' => 10000],
            ['code' => 'TK005', 'name' => 'TikTok Comment Video', 'category_name' => 'TikTok', 'cost_rate' => 40.00, 'min_quantity' => 10, 'max_quantity' => 5000],
            ['code' => 'TK006', 'name' => 'TikTok Buff Mắt Live', 'category_name' => 'TikTok', 'cost_rate' => 18.00, 'min_quantity' => 100, 'max_quantity' => 5000],
            ['code' => 'TK007', 'name' => 'TikTok Thả Tim Livestream', 'category_name' => 'TikTok', 'cost_rate' => 5.00, 'min_quantity' => 100, 'max_quantity' => 50000],

            // Instagram
            ['code' => 'IG001', 'name' => 'Instagram Like Ảnh/Video', 'category_name' => 'Instagram', 'cost_rate' => 5.00, 'min_quantity' => 100, 'max_quantity' => 50000],
            ['code' => 'IG002', 'name' => 'Instagram Follow - Thế Giới', 'category_name' => 'Instagram', 'cost_rate' => 9.00, 'min_quantity' => 100, 'max_quantity' => 20000],
            ['code' => 'IG003', 'name' => 'Instagram Comment', 'category_name' => 'Instagram', 'cost_rate' => 45.00, 'min_quantity' => 10, 'max_quantity' => 3000],

            // YouTube
            ['code' => 'YT001', 'name' => 'YouTube View - Việt Nam', 'category_name' => 'YouTube', 'cost_rate' => 2.00, 'min_quantity' => 500, 'max_quantity' => 500000],
            ['code' => 'YT002', 'name' => 'YouTube Like Video', 'category_name' => 'YouTube', 'cost_rate' => 6.00, 'min_quantity' => 100, 'max_quantity' => 20000],
            ['code' => 'YT003', 'name' => 'YouTube Subscribe', 'category_name' => 'YouTube', 'cost_rate' => 12.00, 'min_quantity' => 100, 'max_quantity' => 10000],
            ['code' => 'YT004', 'name' => 'YouTube Comment', 'category_name' => 'YouTube', 'cost_rate' => 55.00, 'min_quantity' => 10, 'max_quantity' => 2000],

            // Twitter/X
            ['code' => 'TW001', 'name' => 'Twitter Like Tweet', 'category_name' => 'Twitter', 'cost_rate' => 6.00, 'min_quantity' => 100, 'max_quantity' => 20000],
            ['code' => 'TW002', 'name' => 'Twitter Follow', 'category_name' => 'Twitter', 'cost_rate' => 10.00, 'min_quantity' => 100, 'max_quantity' => 10000],
        ];

        $providerServiceIds = [];
        foreach ($providerServices as $ps) {
            $existing = DB::table('provider_services')
                ->where('provider_id', $providerId)
                ->where('provider_service_code', $ps['code'])
                ->first();

            if ($existing) {
                $providerServiceIds[$ps['code']] = $existing->id;
                continue;
            }

            $id = DB::table('provider_services')->insertGetId([
                'provider_id'           => $providerId,
                'provider_service_code' => $ps['code'],
                'name'                  => $ps['name'],
                'category_name'         => $ps['category_name'],
                'cost_rate'             => $ps['cost_rate'],
                'min_quantity'          => $ps['min_quantity'],
                'max_quantity'          => $ps['max_quantity'],
                'is_active'             => 1,
                'last_synced_at'        => now(),
                'created_at'            => now(),
                'updated_at'            => now(),
            ]);
            $providerServiceIds[$ps['code']] = $id;
        }

        // ============================================================
        // 2. THÊM SERVICES (dịch vụ bán cho khách)
        // ============================================================
        $services = [
            // Facebook
            [
                'provider_code'  => 'FB001',
                'name'           => 'Like Facebook Bài Viết - Việt Nam',
                'description'    => 'Tăng like bài viết Facebook từ tài khoản Việt Nam thật. Tốc độ nhanh, ổn định.',
                'platform'       => '1',
                'sell_rate'      => 7.00,
                'agent_price'    => 5.50,
                'min_quantity'   => 100,
                'max_quantity'   => 50000,
                'sort_order'     => 1,
                'priority'       => 2,
                'service_status' => 0,
            ],
            [
                'provider_code'  => 'FB002',
                'name'           => 'Follow Facebook - Việt Nam',
                'description'    => 'Tăng follow Facebook từ tài khoản Việt Nam thật. Bảo hành 30 ngày.',
                'platform'       => '1',
                'sell_rate'      => 10.00,
                'agent_price'    => 8.50,
                'min_quantity'   => 100,
                'max_quantity'   => 10000,
                'sort_order'     => 2,
                'priority'       => 2,
                'service_status' => 0,
            ],
            [
                'provider_code'  => 'FB003',
                'name'           => 'Like Page Facebook',
                'description'    => 'Tăng like page Facebook chất lượng cao, ổn định lâu dài.',
                'platform'       => '1',
                'sell_rate'      => 13.00,
                'agent_price'    => 11.00,
                'min_quantity'   => 100,
                'max_quantity'   => 20000,
                'sort_order'     => 3,
                'priority'       => 2,
                'service_status' => 0,
            ],
            [
                'provider_code'  => 'FB004',
                'name'           => 'Reaction Phẫn Nộ Facebook',
                'description'    => 'Tăng reaction phẫn nộ (angry) bài viết Facebook nhanh chóng.',
                'platform'       => '1',
                'sell_rate'      => 8.00,
                'agent_price'    => 6.50,
                'min_quantity'   => 100,
                'max_quantity'   => 50000,
                'sort_order'     => 4,
                'priority'       => 2,
                'service_status' => 0,
            ],
            [
                'provider_code'  => 'FB005',
                'name'           => 'Reaction Haha Facebook',
                'description'    => 'Tăng reaction haha bài viết Facebook nhanh chóng.',
                'platform'       => '1',
                'sell_rate'      => 8.00,
                'agent_price'    => 6.50,
                'min_quantity'   => 100,
                'max_quantity'   => 50000,
                'sort_order'     => 5,
                'priority'       => 2,
                'service_status' => 0,
            ],
            [
                'provider_code'  => 'FB006',
                'name'           => 'Share Bài Viết Facebook',
                'description'    => 'Tăng share bài viết Facebook từ tài khoản thật.',
                'platform'       => '1',
                'sell_rate'      => 20.00,
                'agent_price'    => 16.00,
                'min_quantity'   => 50,
                'max_quantity'   => 10000,
                'sort_order'     => 6,
                'priority'       => 1,
                'service_status' => 0,
            ],
            [
                'provider_code'  => 'FB007',
                'name'           => 'Comment Bài Viết Facebook',
                'description'    => 'Comment bài viết Facebook bằng nội dung tùy chỉnh.',
                'platform'       => '1',
                'sell_rate'      => 65.00,
                'agent_price'    => 52.00,
                'min_quantity'   => 10,
                'max_quantity'   => 5000,
                'sort_order'     => 7,
                'priority'       => 1,
                'service_status' => 0,
            ],
            [
                'provider_code'  => 'FB008',
                'name'           => 'Buff View Livestream Facebook',
                'description'    => 'Tăng mắt xem livestream Facebook, duy trì ổn định trong suốt buổi live.',
                'platform'       => '1',
                'sell_rate'      => 25.00,
                'agent_price'    => 21.00,
                'min_quantity'   => 100,
                'max_quantity'   => 5000,
                'sort_order'     => 8,
                'priority'       => 3,
                'service_status' => 0,
            ],
            [
                'provider_code'  => 'FB009',
                'name'           => 'Tăng Member Nhóm Facebook',
                'description'    => 'Tăng thành viên nhóm Facebook từ tài khoản Việt Nam.',
                'platform'       => '1',
                'sell_rate'      => 15.00,
                'agent_price'    => 13.00,
                'min_quantity'   => 100,
                'max_quantity'   => 10000,
                'sort_order'     => 9,
                'priority'       => 1,
                'service_status' => 0,
            ],

            // TikTok
            [
                'provider_code'  => 'TK001',
                'name'           => 'Like TikTok - Nhanh',
                'description'    => 'Tăng like video TikTok siêu tốc, bắt đầu trong vài phút.',
                'platform'       => '2',
                'sell_rate'      => 5.50,
                'agent_price'    => 4.50,
                'min_quantity'   => 100,
                'max_quantity'   => 100000,
                'sort_order'     => 10,
                'priority'       => 3,
                'service_status' => 0,
            ],
            [
                'provider_code'  => 'TK002',
                'name'           => 'Follow TikTok - Việt Nam',
                'description'    => 'Tăng follow TikTok từ tài khoản Việt Nam thật, ổn định lâu dài.',
                'platform'       => '2',
                'sell_rate'      => 9.00,
                'agent_price'    => 7.50,
                'min_quantity'   => 100,
                'max_quantity'   => 50000,
                'sort_order'     => 11,
                'priority'       => 2,
                'service_status' => 0,
            ],
            [
                'provider_code'  => 'TK003',
                'name'           => 'View TikTok - Giá Rẻ',
                'description'    => 'Tăng lượt xem video TikTok giá cực rẻ, tốc độ cao.',
                'platform'       => '2',
                'sell_rate'      => 2.00,
                'agent_price'    => 1.70,
                'min_quantity'   => 1000,
                'max_quantity'   => 500000,
                'sort_order'     => 12,
                'priority'       => 3,
                'service_status' => 0,
            ],
            [
                'provider_code'  => 'TK004',
                'name'           => 'Share TikTok',
                'description'    => 'Tăng share video TikTok từ tài khoản thật.',
                'platform'       => '2',
                'sell_rate'      => 13.00,
                'agent_price'    => 11.00,
                'min_quantity'   => 100,
                'max_quantity'   => 10000,
                'sort_order'     => 13,
                'priority'       => 2,
                'service_status' => 0,
            ],
            [
                'provider_code'  => 'TK005',
                'name'           => 'Comment TikTok',
                'description'    => 'Comment video TikTok bằng nội dung tùy chỉnh.',
                'platform'       => '2',
                'sell_rate'      => 55.00,
                'agent_price'    => 43.00,
                'min_quantity'   => 10,
                'max_quantity'   => 5000,
                'sort_order'     => 14,
                'priority'       => 1,
                'service_status' => 0,
            ],
            [
                'provider_code'  => 'TK006',
                'name'           => 'Buff Mắt Live TikTok',
                'description'    => 'Tăng mắt xem livestream TikTok, ổn định trong suốt buổi live.',
                'platform'       => '2',
                'sell_rate'      => 23.00,
                'agent_price'    => 19.00,
                'min_quantity'   => 100,
                'max_quantity'   => 5000,
                'sort_order'     => 15,
                'priority'       => 3,
                'service_status' => 0,
            ],
            [
                'provider_code'  => 'TK007',
                'name'           => 'Thả Tim Livestream TikTok',
                'description'    => 'Tăng tim trong livestream TikTok, tương tác thật.',
                'platform'       => '2',
                'sell_rate'      => 7.00,
                'agent_price'    => 5.50,
                'min_quantity'   => 100,
                'max_quantity'   => 50000,
                'sort_order'     => 16,
                'priority'       => 3,
                'service_status' => 0,
            ],

            // Instagram
            [
                'provider_code'  => 'IG001',
                'name'           => 'Like Instagram - Thế Giới',
                'description'    => 'Tăng like ảnh/video Instagram từ tài khoản thật trên toàn thế giới.',
                'platform'       => '4',
                'sell_rate'      => 7.00,
                'agent_price'    => 5.50,
                'min_quantity'   => 100,
                'max_quantity'   => 50000,
                'sort_order'     => 17,
                'priority'       => 2,
                'service_status' => 0,
            ],
            [
                'provider_code'  => 'IG002',
                'name'           => 'Follow Instagram - Thế Giới',
                'description'    => 'Tăng follow Instagram chất lượng cao, bảo hành 30 ngày.',
                'platform'       => '4',
                'sell_rate'      => 12.00,
                'agent_price'    => 9.50,
                'min_quantity'   => 100,
                'max_quantity'   => 20000,
                'sort_order'     => 18,
                'priority'       => 2,
                'service_status' => 0,
            ],
            [
                'provider_code'  => 'IG003',
                'name'           => 'Comment Instagram',
                'description'    => 'Comment bài viết Instagram bằng nội dung tùy chỉnh.',
                'platform'       => '4',
                'sell_rate'      => 60.00,
                'agent_price'    => 47.00,
                'min_quantity'   => 10,
                'max_quantity'   => 3000,
                'sort_order'     => 19,
                'priority'       => 1,
                'service_status' => 0,
            ],

            // YouTube
            [
                'provider_code'  => 'YT001',
                'name'           => 'View YouTube - Việt Nam',
                'description'    => 'Tăng lượt xem YouTube từ IP Việt Nam, an toàn, không vi phạm chính sách.',
                'platform'       => '5',
                'sell_rate'      => 3.00,
                'agent_price'    => 2.20,
                'min_quantity'   => 500,
                'max_quantity'   => 500000,
                'sort_order'     => 20,
                'priority'       => 2,
                'service_status' => 0,
            ],
            [
                'provider_code'  => 'YT002',
                'name'           => 'Like YouTube',
                'description'    => 'Tăng like video YouTube nhanh chóng, ổn định.',
                'platform'       => '5',
                'sell_rate'      => 8.00,
                'agent_price'    => 6.50,
                'min_quantity'   => 100,
                'max_quantity'   => 20000,
                'sort_order'     => 21,
                'priority'       => 2,
                'service_status' => 0,
            ],
            [
                'provider_code'  => 'YT003',
                'name'           => 'Subscribe YouTube',
                'description'    => 'Tăng subscriber YouTube từ tài khoản thật, bảo hành 30 ngày.',
                'platform'       => '5',
                'sell_rate'      => 15.00,
                'agent_price'    => 13.00,
                'min_quantity'   => 100,
                'max_quantity'   => 10000,
                'sort_order'     => 22,
                'priority'       => 2,
                'service_status' => 0,
            ],
            [
                'provider_code'  => 'YT004',
                'name'           => 'Comment YouTube',
                'description'    => 'Comment video YouTube bằng nội dung tùy chỉnh.',
                'platform'       => '5',
                'sell_rate'      => 70.00,
                'agent_price'    => 57.00,
                'min_quantity'   => 10,
                'max_quantity'   => 2000,
                'sort_order'     => 23,
                'priority'       => 1,
                'service_status' => 0,
            ],

            // Twitter
            [
                'provider_code'  => 'TW001',
                'name'           => 'Like Twitter/X',
                'description'    => 'Tăng like tweet Twitter/X từ tài khoản thật.',
                'platform'       => '3',
                'sell_rate'      => 8.00,
                'agent_price'    => 6.50,
                'min_quantity'   => 100,
                'max_quantity'   => 20000,
                'sort_order'     => 24,
                'priority'       => 2,
                'service_status' => 0,
            ],
            [
                'provider_code'  => 'TW002',
                'name'           => 'Follow Twitter/X',
                'description'    => 'Tăng follow Twitter/X chất lượng cao.',
                'platform'       => '3',
                'sell_rate'      => 13.00,
                'agent_price'    => 11.00,
                'min_quantity'   => 100,
                'max_quantity'   => 10000,
                'sort_order'     => 25,
                'priority'       => 2,
                'service_status' => 0,
            ],
        ];

        // Map platform code -> category_group name
        $platformGroupMap = [
            '1' => 'Facebook',
            '2' => 'TikTok',
            '3' => 'Twitter',
            '4' => 'Instagram',
            '5' => 'YouTube',
        ];

        foreach ($services as $s) {
            $providerServiceId = $providerServiceIds[$s['provider_code']] ?? null;
            if (!$providerServiceId) continue;

            $exists = DB::table('services')
                ->where('provider_service_id', $providerServiceId)
                ->exists();
            if ($exists) continue;

            // Tìm category_group_id phù hợp
            $groupId = $categoryGroups->get($platformGroupMap[$s['platform']] ?? '', $defaultGroupId);

            DB::table('services')->insert([
                'category_group_id'  => $groupId,
                'provider_service_id' => $providerServiceId,
                'name'               => $s['name'],
                'description'        => $s['description'],
                'platform'           => $s['platform'],
                'sell_rate'          => $s['sell_rate'],
                'agent_price'        => $s['agent_price'],
                'min_quantity'       => $s['min_quantity'],
                'max_quantity'       => $s['max_quantity'],
                'sort_order'         => $s['sort_order'],
                'priority'           => $s['priority'],
                'service_status'     => $s['service_status'],
                'is_active'          => 1,
                'api_accessible'     => 1,
                'allow_multiple_reactions' => 0,
                'created_at'         => now(),
                'updated_at'         => now(),
            ]);
        }

        $count = count($services);
        $this->command->info("Đã thêm {$count} dịch vụ mẫu.");
    }
}
