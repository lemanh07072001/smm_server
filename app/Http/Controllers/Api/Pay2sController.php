<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DepositLog;
use App\Models\Setting;
use App\Services\DepositService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class Pay2sController extends Controller
{
    private DepositService $depositService;

    public function __construct(DepositService $depositService)
    {
        $this->depositService = $depositService;
    }

    /**
     * Pay2s webhook endpoint.
     * POST /api/webhook/pay2s
     *
     * Payload:
     * {
     *   "transactions": [
     *     {
     *       "id": 26191024,
     *       "gateway": "VCB",
     *       "transactionDate": "2026-03-04 11:42:10",
     *       "transactionNumber": "5414 - 63179",
     *       "accountNumber": "1056968673",
     *       "content": "SMM...",
     *       "transferType": "IN",
     *       "transferAmount": 50000,
     *       "checksum": "bfb853fb3c28f21732f2884e170b0d44"
     *     }
     *   ]
     * }
     */
    public function webhook(Request $request): JsonResponse
    {
        $payload = $request->all();
        $ip      = $request->ip();

        // Bước 1: Ghi log webhook nhận được
        $this->mlog('webhook_received', 'info', [
            'source'      => 'pay2s',
            'ip'          => $ip,
            'raw_payload' => $payload,
            'message'     => 'Pay2s webhook nhận được',
        ]);

        // Bước 2: Xác thực token
        $expectedToken = Setting::where('key', 'pay2s_webhook_token')->value('value');
        if (!empty($expectedToken)) {
            $authHeader = $request->header('Authorization', '');
            if (!hash_equals('Bearer ' . $expectedToken, $authHeader)) {
                $this->mlog('auth_failed', 'error', [
                    'source'  => 'pay2s',
                    'ip'      => $ip,
                    'message' => 'Token không hợp lệ, từ chối webhook',
                ]);
                Log::warning('Pay2s webhook: invalid token', ['ip' => $ip]);
                return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
            }
        }

        $this->mlog('auth_passed', 'success', [
            'source'  => 'pay2s',
            'ip'      => $ip,
            'message' => 'Xác thực token thành công',
        ]);

        Log::info('Pay2s webhook received', $payload);

        $transactions = $payload['transactions'] ?? [];

        if (empty($transactions)) {
            $this->mlog('no_transactions', 'info', [
                'source'  => 'pay2s',
                'message' => 'Payload không có transactions, bỏ qua',
            ]);
            return response()->json(['success' => true]);
        }

        foreach ($transactions as $transaction) {
            $this->handleTransaction($transaction);
        }

        return response()->json(['success' => true]);
    }

    private function handleTransaction(array $transaction): void
    {
        $pay2sId = $transaction['id'] ?? null;

        // Bước 3: Validate loại giao dịch
        if (strtoupper($transaction['transferType'] ?? '') !== 'IN') {
            $this->mlog('skip_transfer_out', 'info', [
                'source'      => 'pay2s',
                'pay2s_id'    => $pay2sId,
                'raw_payload' => $transaction,
                'message'     => 'Bỏ qua giao dịch tiền ra (transferType != IN)',
            ]);
            return;
        }

        $amount          = (int) ($transaction['transferAmount'] ?? 0);
        $content         = $transaction['content'] ?? '';
        $transactionDate = $transaction['transactionDate'] ?? null;

        if ($amount <= 0 || !$pay2sId) {
            $this->mlog('invalid_transaction', 'error', [
                'source'      => 'pay2s',
                'pay2s_id'    => $pay2sId,
                'amount'      => $amount,
                'raw_payload' => $transaction,
                'message'     => 'Giao dịch không hợp lệ: amount <= 0 hoặc thiếu id',
            ]);
            Log::warning('Pay2s webhook: invalid transaction', ['transaction' => $transaction]);
            return;
        }

        // Bước 4: Tạo transaction ID nội bộ
        $transactionId = 'P2S_' . $pay2sId;

        // Bước 4.5: Dedup tầng 1 — kiểm tra is_processed trước khi xử lý
        // Dùng atomic UPDATE WHERE is_processed=false → trả về số rows affected
        // Race condition: 2 webhook cùng lúc → chỉ 1 cái affected=1, cái còn lại affected=0 → bỏ qua
        $existingRecord = \App\Models\BankAuto::where('tid', $transactionId)->first();
        if ($existingRecord) {
            if ($existingRecord->is_processed) {
                $this->mlog('already_processed', 'warning', [
                    'source'      => 'pay2s',
                    'tid'         => $transactionId,
                    'pay2s_id'    => $pay2sId,
                    'raw_payload' => $transaction,
                    'message'     => 'Giao dịch đã được xử lý (is_processed=true), bỏ qua',
                ]);
                return;
            }
        }

        $this->mlog('transaction_validate', 'success', [
            'source'          => 'pay2s',
            'pay2s_id'        => $pay2sId,
            'tid'             => $transactionId,
            'amount'          => $amount,
            'content'         => $content,
            'transaction_date'=> $transactionDate,
            'raw_payload'     => $transaction,
            'message'         => 'Validate giao dịch thành công',
        ]);

        // Bước 5: Tìm user từ nội dung chuyển khoản
        $userId = $this->depositService->findUserFromContent($content);

        if (!$userId) {
            $this->mlog('find_user_failed', 'warning', [
                'source'      => 'pay2s',
                'tid'         => $transactionId,
                'amount'      => $amount,
                'content'     => $content,
                'raw_payload' => $transaction,
                'message'     => 'Không tìm thấy user từ nội dung chuyển khoản',
            ]);

            // Kiểm tra trùng trước khi lưu
            if ($this->depositService->isDuplicateTransaction($transactionId)) {
                $this->mlog('skip_duplicate_no_user', 'warning', [
                    'source'      => 'pay2s',
                    'tid'         => $transactionId,
                    'raw_payload' => $transaction,
                    'message'     => 'Trùng tid, không có user, bỏ qua',
                ]);
                Log::info('Pay2s webhook: duplicate, no user', ['id' => $pay2sId]);
                return;
            }

            $saved = $this->depositService->savePendingDeposit(
                $transactionId,
                $content,
                $transactionDate,
                $amount,
                $transaction,
                'pay2s'
            );

            $this->mlog('saved_no_user', 'warning', [
                'source'       => 'pay2s',
                'tid'          => $transactionId,
                'amount'       => $amount,
                'bank_auto_id' => $saved->id,
                'raw_payload'  => $transaction,
                'message'      => 'Lưu pending chờ admin gán user',
            ]);

            Log::info('Pay2s webhook: no user found, saved pending', [
                'id'      => $pay2sId,
                'amount'  => $amount,
                'content' => $content,
            ]);

            return;
        }

        $this->mlog('find_user_success', 'success', [
            'source'      => 'pay2s',
            'tid'         => $transactionId,
            'user_id'     => $userId,
            'amount'      => $amount,
            'content'     => $content,
            'raw_payload' => $transaction,
            'message'     => 'Tìm thấy user từ nội dung chuyển khoản',
        ]);

        // Bước 6: Xử lý nạp tiền
        $result = $this->depositService->processDeposit(
            $userId,
            $amount,
            $transactionId,
            $content,
            $transactionDate,
            $transaction,
            'pay2s'
        );

        $this->mlog('process_deposit_result', $result['success'] ? 'success' : 'warning', [
            'source'      => 'pay2s',
            'tid'         => $transactionId,
            'user_id'     => $userId,
            'amount'      => $amount,
            'raw_payload' => $transaction,
            'result'      => $result,
            'message'     => $result['message'] ?? ($result['success'] ? 'Xử lý thành công' : 'Xử lý thất bại'),
        ]);
    }

    private function mlog(string $step, string $status, array $data = []): void
    {
        try {
            DepositLog::create(array_merge(['step' => $step, 'status' => $status], $data));
        } catch (\Exception $e) {
            Log::error('DepositLog MongoDB write failed', ['step' => $step, 'error' => $e->getMessage()]);
        }
    }
}
