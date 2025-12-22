<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CodeTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CodeTransactionController extends Controller
{
    /**
     * Store a newly created code transaction.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:255'],
        ], [
            'code.required' => 'Mã giao dịch là bắt buộc.',
            'code.string' => 'Mã giao dịch phải là chuỗi.',
            'code.max' => 'Mã giao dịch không được vượt quá 255 ký tự.',
        ]);

        // Map field 'cide' từ frontend sang 'transaction_code' trong database
        $codeTransaction = CodeTransaction::create([
            'transaction_code' => $validated['code'],
        ]);

        // Lưu mã giao dịch vào Redis với connection riêng
        try {
            $redis = Redis::connection('code_transactions_redis');
            $redisKey = 'code_transaction:' . $codeTransaction->id;
            $redisData = [
                'id' => $codeTransaction->id,
                'transaction_code' => $codeTransaction->transaction_code,
                'created_at' => $codeTransaction->created_at->toDateTimeString(),
                'updated_at' => $codeTransaction->updated_at->toDateTimeString(),
            ];
            
            // Lưu vào Redis với TTL 30 ngày (2592000 giây)
            $redis->setex($redisKey, 2592000, json_encode($redisData));

        } catch (\Exception $redisException) {
            // Nếu Redis lỗi, vẫn trả về success nhưng log warning
            logger()->warning('Failed to save code transaction to Redis', [
                'code_transaction_id' => $codeTransaction->id,
                'error' => $redisException->getMessage(),
            ]);
        }


        return response()->json([
            'message' => 'Thêm mã giao dịch thành công.',
            'data' => $codeTransaction,
        ], 201);
    }
}

