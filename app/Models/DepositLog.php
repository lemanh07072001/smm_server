<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class DepositLog extends Model
{
    protected $connection = 'mongodb';
    protected $collection = 'deposit_logs';

    protected $fillable = [
        'step',        // tên bước xử lý
        'status',      // success | warning | error
        'source',      // pay2s | sepay | macrodroid
        'tid',
        'user_id',
        'amount',
        'expected_amount',
        'deposit_code',
        'bank_auto_id',
        'message',
        'context',     // dữ liệu thô thêm
        'raw_payload', // payload gốc từ provider
    ];
}
