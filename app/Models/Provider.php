<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Provider extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'api_url',
        'api_key',
        'balance',
        'balance_updated_at',
        'is_active',
        'notes',
        'image',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'balance' => 'decimal:6',
        'balance_updated_at' => 'datetime',
    ];

    public function services(): HasMany
    {
        return $this->hasMany(ProviderService::class);
    }
}
