<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserServicePrice extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'service_id',
        'sell_rate',
    ];

    protected $casts = [
        'user_id'    => 'integer',
        'service_id' => 'integer',
        'sell_rate'  => 'decimal:6',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }
}
