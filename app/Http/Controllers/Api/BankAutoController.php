<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DepositService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class BankAutoController extends Controller
{
    private DepositService $depositService;

    public function __construct(DepositService $depositService)
    {
        $this->depositService = $depositService;
    }

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
        if ($this->depositService->isDuplicateTransaction($transactionId)) {
            return response()->json([
                'success' => false,
                'message' => 'Giao dịch đã được xử lý'
            ], 409);
        }

        // Tìm user từ mã giao dịch trong SMS
        $userId = $this->depositService->findUserFromContent($sms);

        if (!$userId) {
            // Lưu giao dịch nhưng không nạp tiền (cần xử lý thủ công)
            $this->depositService->savePendingDeposit(
                $transactionId,
                $sms,
                $time,
                $amount,
                $request->all()
            );

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
        $result = $this->depositService->processDeposit(
            $userId,
            $amount,
            $transactionId,
            $sms,
            $time,
            $request->all(),
            'macrodroid'
        );

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
        $userId = $this->depositService->findUserFromContent($sms);
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
