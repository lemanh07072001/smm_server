<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BankAuto extends Model
{
    use HasFactory;

    protected $table = 'bank_auto';

    protected $fillable = [
        'tid',
        'description',
        'date',
        'data',
        'amount',
        'user_id',
        'transaction_type',
        'type',
        'status',
        'note',
        'deposit_type',
    ];

    protected $casts = [
        'amount' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function dongtiens(): HasMany
    {
        return $this->hasMany(Dongtien::class);
    }
}
