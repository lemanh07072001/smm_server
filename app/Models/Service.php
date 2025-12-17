<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Service extends Model
{
    use HasFactory;

    protected $fillable = [
        'category_id',
        'platform_id',
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
    ];

    protected $casts = [
        'category_id' => 'integer',
        'platform_id' => 'integer',
        'group_id' => 'integer',
        'provider_service_id' => 'integer',
        'sell_rate' => 'decimal:6',
        'min_quantity' => 'integer',
        'max_quantity' => 'integer',
        'sort_order' => 'integer',
        'priority' => 'integer',
        'is_active' => 'boolean',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function providerService(): BelongsTo
    {
        return $this->belongsTo(ProviderService::class);
    }
}
