<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransactionBank extends Model
{
    protected $fillable = [
        'tid',
        'source',
        'transfer_type',
        'amount',
        'content',
        'account_number',
        'bank_code',
        'transacted_at',
        'is_processed',
        'bank_auto_id',
        'raw_data',
    ];

    protected $casts = [
        'amount'        => 'integer',
        'is_processed'  => 'boolean',
        'transacted_at' => 'datetime',
        'raw_data'      => 'array',
    ];

    public function bankAuto(): BelongsTo
    {
        return $this->belongsTo(BankAuto::class);
    }
}
