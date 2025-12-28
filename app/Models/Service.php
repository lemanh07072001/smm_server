<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Service extends Model
{
    use HasFactory;

    // Priority types
    public const PRIORITY_VERY_SLOW = 0;
    public const PRIORITY_SLOW = 1;
    public const PRIORITY_NORMAL = 2;
    public const PRIORITY_FAST = 3;
    public const PRIORITY_VERY_FAST = 4;

    public const PRIORITY_TYPES = [
        self::PRIORITY_VERY_SLOW => 'Rất chậm',
        self::PRIORITY_SLOW      => 'Chậm',
        self::PRIORITY_NORMAL    => 'Bình thường',
        self::PRIORITY_FAST      => 'Nhanh',
        self::PRIORITY_VERY_FAST => 'Rất nhanh',
    ];

    public const PLATFORM = [
        '1' => [
            'CODE'  => '1',
            'TEXT'  => 'Facebook',
            'group' => [
                'fb_feel'           => 'Cảm xúc Facebook',
                'fb_follow'         => 'Follow Facebook',
                'fb_like_page'      => 'Like page Facebook',
                'fb_join_group'     => 'Join group Facebook',
                'fb_review'         => 'Review Facebook',
                'fb_like_cmt'       => 'Like comment Facebook',
                'fb_share'          => 'Share Facebook',
                'fb_comment'        => 'Comment Facebook',
                'fb_mix'            => 'Facebook Mix',
                'fb_add_friends'    => 'Facebook Add Friends',
            ]
        ],
        '2' => [
            'CODE'  => '2',
            'TEXT'  => 'Tiktok',
            'group' => [
                'tiktok_like'                               => 'Like Tiktok',
                'tiktok_like_livestream_multiple_in_post'   => 'Like Tiktok',
                'tiktok_follow'                             => 'Follow Tiktok',
                'tiktok_comment'                            => 'Comment Tiktok',
                'tiktok_comment_livestream'                 => 'Comment Tiktok Livestream',
                'tiktok_share'                              => 'Share Tiktok',
            ]
        ],
        '3' => [
            'CODE'  => '3',
            'TEXT'  => 'Twitter',
            'group' => [
                'twitter_like'      => 'Like Twitter',
                'twitter_comment'   => 'Comment Twitter',
                'twitter_follow'    => 'Follow Twitter',
                'twitter_mix'       => 'Mix Twitter',
            ]
        ],
        '4' => [
            'CODE'  => '4',
            'TEXT'  => 'Instagram',
            'group' => [
                'ig_like'      => 'Like Instagram',
                'ig_comment'   => 'Comment Instagram',
                'ig_follow'    => 'Follow Instagram',
            ]
        ],
        '5' => [
            'CODE'  => '5',
            'TEXT'  => 'Youtube',
            'group' => [
                'youtube_like'          => 'Like Youtube',
                'youtube_like_all'      => 'Like All Name Youtube',
                'youtube_dislike'       => 'Dislike Youtube',
                'youtube_dislike_all'   => 'Dislike All Name Youtube',
                'youtube_comment'       => 'Comment Youtube',
                'youtube_comment_all'   => 'Comment Youtube',
                'youtube_follow'        => 'Follow Youtube',
                'youtube_follow_all'    => 'Follow Youtube',
            ]
        ],
        '6' => [
            'CODE'  => '6',
            'TEXT'  => 'Zalo',
            'group' => [
                'zalo_send_message_multiple' => 'Zalo Send Message Multiple',
            ]
        ],
    ];

    public const FEEL_FORM = [
        'fb_feel'
    ];

    public const COMMENT_FORM = [
        'fb_comment'
    ];

    protected $fillable = [
        'category_group_id',
        'group_id',
        'provider_service_id',
        'name',
        'description',
        'sell_rate',
        'min_quantity',
        'max_quantity',
        'sort_order',
        'priority',
        'is_active',
        'allow_multiple_reactions',
        'reaction_types',
    ];

    protected $casts = [
        'category_group_id' => 'integer',
        'group_id' => 'string',
        'provider_service_id' => 'integer',
        'sell_rate' => 'decimal:2',
        'min_quantity' => 'integer',
        'max_quantity' => 'integer',
        'sort_order' => 'integer',
        'priority' => 'integer',
        'is_active' => 'boolean',
        'allow_multiple_reactions' => 'boolean',
        'reaction_types' => 'array',
    ];

    public function categoryGroup(): BelongsTo
    {
        return $this->belongsTo(CategoryGroup::class);
    }

    public function providerService(): BelongsTo
    {
        return $this->belongsTo(ProviderService::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Lấy tên priority
     */
    public function getPriorityTextAttribute(): string
    {
        return self::PRIORITY_TYPES[$this->priority] ?? 'Không xác định';
    }
}
