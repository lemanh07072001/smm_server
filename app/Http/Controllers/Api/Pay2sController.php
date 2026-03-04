<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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

        Log::info('Pay2s webhook received', $payload);

        $transactions = $payload['transactions'] ?? [];

        if (empty($transactions)) {
            return response()->json(['success' => true]);
        }

        foreach ($transactions as $transaction) {
            $this->handleTransaction($transaction);
        }

        return response()->json(['success' => true]);
    }

    private function handleTransaction(array $transaction): void
    {
        // 1. Chỉ xử lý tiền vào
        if (strtoupper($transaction['transferType'] ?? '') !== 'IN') {
            return;
        }

        $pay2sId         = $transaction['id'] ?? null;
        $amount          = (int) ($transaction['transferAmount'] ?? 0);
        $content         = $transaction['content'] ?? '';
        $transactionDate = $transaction['transactionDate'] ?? null;

        if ($amount <= 0 || !$pay2sId) {
            Log::warning('Pay2s webhook: invalid transaction', ['transaction' => $transaction]);
            return;
        }

        // 2. Transaction ID duy nhất
        $transactionId = 'P2S_' . $pay2sId;

        // 3. Tìm user từ nội dung
        $userId = $this->depositService->findUserFromContent($content);

        if (!$userId) {
            // Kiểm tra trùng trước khi lưu pending
            if ($this->depositService->isDuplicateTransaction($transactionId)) {
                Log::info('Pay2s webhook: duplicate, no user', ['id' => $pay2sId]);
                return;
            }

            $this->depositService->savePendingDeposit(
                $transactionId,
                $content,
                $transactionDate,
                $amount,
                $transaction
            );

            Log::info('Pay2s webhook: no user found, saved pending', [
                'id'      => $pay2sId,
                'amount'  => $amount,
                'content' => $content,
            ]);

            return;
        }

        // 4. Xử lý nạp tiền (processDeposit tự xử lý trùng lặp bên trong)
        $this->depositService->processDeposit(
            $userId,
            $amount,
            $transactionId,
            $content,
            $transactionDate,
            $transaction,
            'pay2s'
        );
    }
}
