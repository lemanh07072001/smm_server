<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BankAuto;
use App\Models\User;
use App\Models\Dongtien;
use App\Models\CodeTransaction;
use App\Helpers\RedisHelper;
use App\Events\DepositSuccess;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;

class BankAutoController extends Controller
{
    /**
     * Webhook endpoint cho Macrodroid
     * Nhận thông báo SMS từ ngân hàng và xử lý nạp tiền tự động
     *
     * POST /api/webhook/macrodroid
     *
     * Request body:
     * {
     *     "sms": "Nội dung SMS từ ngân hàng",
     *     "amount": 100000,           // Số tiền (optional, sẽ parse từ SMS nếu không có)
     *     "transaction_id": "FT123",  // Mã giao dịch ngân hàng (optional)
     *     "bank": "VCB",              // Tên ngân hàng (optional)
     *     "time": "2024-01-15 10:30:00", // Thời gian giao dịch (optional)
     *     "secret": "your_secret_key" // Secret key để xác thực
     * }
     */
    public function macrodroidWebhook(Request $request): JsonResponse
    {
        // Validate secret key
        $secretKey = config('services.macrodroid.secret', env('MACRODROID_SECRET'));
        if ($secretKey && $request->input('secret') !== $secretKey) {
            Log::warning('Macrodroid webhook: Invalid secret key', [
                'ip' => $request->ip(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 401);
        }

        $sms = $request->input('sms', '');
        $amount = $request->input('amount');
        $transactionId = $request->input('transaction_id');
        $bank = $request->input('bank', 'unknown');
        $time = $request->input('time');

        Log::info('Macrodroid webhook received', [
            'sms' => $sms,
            'amount' => $amount,
            'transaction_id' => $transactionId,
            'bank' => $bank,
            'time' => $time,
        ]);

        // Parse amount từ SMS nếu không được cung cấp
        if (!$amount) {
            $amount = $this->parseAmountFromSms($sms);
        }

        if (!$amount || $amount <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'Không thể xác định số tiền từ SMS'
            ], 400);
        }

        // Tạo transaction_id nếu không có
        if (!$transactionId) {
            $transactionId = 'MD' . date('YmdHis') . rand(1000, 9999);
        }

        // Kiểm tra trùng lặp
        if (BankAuto::where('tid', $transactionId)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Giao dịch đã được xử lý'
            ], 409);
        }

        // Tìm user từ mã giao dịch trong SMS
        $userId = $this->findUserFromSms($sms);

        if (!$userId) {
            // Lưu giao dịch nhưng không nạp tiền (cần xử lý thủ công)
            BankAuto::create([
                'tid'          => $transactionId,
                'description'  => $sms,
                'date'         => $time ?? now()->format('d/m/Y H:i:s'),
                'data'         => json_encode($request->all()),
                'amount'       => $amount,
                'type'         => 'bank',
                'deposit_type' => 'pending',
                'user_id'      => null,
            ]);

            Log::info('Macrodroid webhook: No user found, saved for manual processing', [
                'transaction_id' => $transactionId,
                'amount' => $amount,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Giao dịch đã lưu, cần xử lý thủ công (không tìm thấy mã user)',
                'transaction_id' => $transactionId,
                'amount' => $amount,
                'user_id' => null,
            ]);
        }

        // Xử lý nạp tiền
        $result = $this->processDeposit($userId, $amount, $transactionId, $sms, $time, $request->all());

        if ($result['success']) {
            return response()->json([
                'success' => true,
                'message' => 'Nạp tiền thành công',
                'transaction_id' => $transactionId,
                'amount' => $amount,
                'user_id' => $userId,
                'new_balance' => $result['new_balance'],
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => $result['message'] ?? 'Lỗi xử lý giao dịch'
        ], 500);
    }

    /**
     * Parse số tiền từ nội dung SMS
     */
    private function parseAmountFromSms(string $sms): ?int
    {
        // Các pattern phổ biến: +100,000 VND, +100.000đ, 100000 VND, v.v.
        $patterns = [
            '/\+?\s*([\d,.]+)\s*(?:VND|VNĐ|đ|d)/iu',
            '/(?:GD|Số tiền|Amount|PS)[\s:]*\+?\s*([\d,.]+)/iu',
            '/(?:cộng|nhận|receive)[\s:]*\+?\s*([\d,.]+)/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $sms, $matches)) {
                $amountStr = preg_replace('/[,.\s]/', '', $matches[1]);
                $amount = (int) $amountStr;
                if ($amount > 0) {
                    return $amount;
                }
            }
        }

        return null;
    }

    /**
     * Tìm user ID từ mã giao dịch trong SMS
     * Pattern: SMM + YYYYMMDD (8 số) + random (6 ký tự) + user_id
     */
    private function findUserFromSms(string $sms): ?int
    {
        $smsLower = strtolower($sms);

        // 1. Thử tìm trong Redis trước
        $userId = $this->findUserFromRedis($smsLower);
        if ($userId) {
            return $userId;
        }

        // 2. Parse trực tiếp từ SMS
        // Pattern: SMM + date(8) + random(6) + user_id
        if (preg_match('/smm(\d{8})(.{6})(\d+)/', $smsLower, $matches)) {
            $userId = (int) $matches[3];
            if ($userId > 0 && User::find($userId)) {
                return $userId;
            }
        }

        return null;
    }

    /**
     * Tìm user từ Redis cache
     */
    private function findUserFromRedis(string $smsLower): ?int
    {
        try {
            $redis = Redis::connection(RedisHelper::REDIS_CODE_TRANSACTIONS);
            $keys = $redis->keys('*');

            if (empty($keys)) {
                return null;
            }

            $values = $redis->mget($keys);

            foreach ($values as $value) {
                if (!$value) continue;

                $data = json_decode($value, true);
                if (!$data || empty($data['transaction_code'])) continue;

                $code = strtolower($data['transaction_code']);
                if (strpos($smsLower, $code) !== false) {
                    // Tìm thấy match, parse user_id
                    if (preg_match('/smm(\d{8})(.{6})(\d+)/', $code, $matches)) {
                        return (int) $matches[3];
                    }
                }
            }
        } catch (\Exception $e) {
            Log::error('Error finding user from Redis', ['error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Xử lý nạp tiền cho user
     */
    private function processDeposit(int $userId, int $amount, string $transactionId, string $sms, ?string $time, array $rawData): array
    {
        DB::beginTransaction();

        try {
            $user = User::find($userId);
            if (!$user) {
                DB::rollBack();
                return ['success' => false, 'message' => 'User không tồn tại'];
            }

            // Tạo BankAuto record
            $bankAuto = BankAuto::create([
                'tid'          => $transactionId,
                'description'  => $sms,
                'date'         => $time ?? now()->format('d/m/Y H:i:s'),
                'data'         => json_encode($rawData),
                'amount'       => $amount,
                'type'         => 'bank',
                'deposit_type' => 'auto',
                'user_id'      => $userId,
            ]);

            // Tạo giao dịch nạp tiền
            $dongtien = Dongtien::createTransaction(
                $user,
                $amount,
                Dongtien::TYPE_DEPOSIT,
                'Nạp tiền thành công (Macrodroid)',
                [
                    'thoigian'       => $time ? date('Y-m-d H:i:s', strtotime($time)) : now(),
                    'payment_method' => 'bank',
                    'payment_ref'    => $transactionId,
                    'datas'          => json_encode($rawData),
                    'bank_auto_id'   => $bankAuto->id,
                ]
            );

            // Xóa mã giao dịch khỏi Redis và DB
            $this->cleanupTransactionCode($sms);

            // Broadcast thông báo
            if ($dongtien) {
                broadcast(new DepositSuccess($dongtien));
            }

            DB::commit();

            Log::info('Macrodroid deposit success', [
                'user_id' => $userId,
                'amount' => $amount,
                'transaction_id' => $transactionId,
                'new_balance' => $user->fresh()->balance,
            ]);

            return [
                'success' => true,
                'new_balance' => $user->fresh()->balance,
            ];

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Macrodroid deposit error', [
                'user_id' => $userId,
                'amount' => $amount,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Xóa mã giao dịch đã xử lý khỏi Redis và Database
     */
    private function cleanupTransactionCode(string $sms): void
    {
        $smsLower = strtolower($sms);

        try {
            $redis = Redis::connection(RedisHelper::REDIS_CODE_TRANSACTIONS);
            $keys = $redis->keys('*');

            if (empty($keys)) {
                return;
            }

            $values = $redis->mget($keys);

            foreach ($keys as $index => $key) {
                $value = $values[$index] ?? null;
                if (!$value) continue;

                $data = json_decode($value, true);
                if (!$data || empty($data['transaction_code'])) continue;

                $code = strtolower($data['transaction_code']);
                if (strpos($smsLower, $code) !== false) {
                    // Xóa khỏi Redis
                    $redis->del($key);

                    // Xóa khỏi Database
                    CodeTransaction::where('transaction_code', $data['transaction_code'])->delete();

                    Log::info('Cleaned up transaction code', ['code' => $data['transaction_code']]);
                    break;
                }
            }
        } catch (\Exception $e) {
            Log::error('Error cleaning up transaction code', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Endpoint test webhook Macrodroid (không thực sự nạp tiền)
     *
     * POST /api/webhook/macrodroid/test
     */
    public function testWebhook(Request $request): JsonResponse
    {
        $sms = $request->input('sms', '');
        $amount = $request->input('amount');
        $transactionId = $request->input('transaction_id');

        // Parse amount từ SMS nếu không được cung cấp
        $parsedAmount = $amount ?: $this->parseAmountFromSms($sms);

        // Tìm user từ mã giao dịch trong SMS
        $userId = $this->findUserFromSms($sms);
        $user = $userId ? \App\Models\User::find($userId) : null;

        // Tìm transaction code trong SMS
        $transactionCode = null;
        if (preg_match('/smm\d{8}.{6}\d+/i', $sms, $matches)) {
            $transactionCode = $matches[0];
        }

        return response()->json([
            'success' => true,
            'message' => 'Test webhook - Không thực sự nạp tiền',
            'parsed_data' => [
                'sms' => $sms,
                'amount_input' => $amount,
                'amount_parsed' => $parsedAmount,
                'transaction_id' => $transactionId ?: 'MD' . date('YmdHis') . rand(1000, 9999),
                'transaction_code_found' => $transactionCode,
                'user_id' => $userId,
                'user_found' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'balance' => $user->balance,
                ] : null,
            ],
            'validation' => [
                'has_amount' => $parsedAmount > 0,
                'has_user' => $userId !== null,
                'would_succeed' => $parsedAmount > 0 && $userId !== null,
            ],
        ]);
    }
}
