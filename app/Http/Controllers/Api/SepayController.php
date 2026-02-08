<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DepositService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SepayController extends Controller
{
    private DepositService $depositService;

    public function __construct(DepositService $depositService)
    {
        $this->depositService = $depositService;
    }

    /**
     * SePay webhook endpoint.
     * POST /api/webhook/sepay
     *
     * Payload:
     * {
     *   "id": 92704,
     *   "gateway": "Vietcombank",
     *   "transactionDate": "2023-03-25 14:02:37",
     *   "accountNumber": "0123499999",
     *   "code": null,
     *   "content": "chuyen tien mua iphone",
     *   "transferType": "in",
     *   "transferAmount": 2277000,
     *   "accumulated": 19077000,
     *   "subAccount": null,
     *   "referenceCode": "MBVCB.3278907687",
     *   "description": ""
     * }
     *
     * Header: Authorization: Apikey API_KEY_CUA_BAN
     */
    public function webhook(Request $request): JsonResponse
    {
        $payload = $request->all();

        Log::info('SePay webhook received', $payload);

        // 1. Chi xu ly tien vao (deposit)
        if (($payload['transferType'] ?? '') !== 'in') {
            Log::info('SePay webhook: Skipping non-deposit transfer', [
                'transferType' => $payload['transferType'] ?? 'unknown',
            ]);
            return response()->json(['success' => true]);
        }

        // 2. Validate cac truong bat buoc
        $sepayId = $payload['id'] ?? null;
        $amount = (int) ($payload['transferAmount'] ?? 0);
        $referenceCode = $payload['referenceCode'] ?? null;
        $content = $payload['content'] ?? '';
        $code = $payload['code'] ?? '';
        $transactionDate = $payload['transactionDate'] ?? null;

        if ($amount <= 0) {
            Log::warning('SePay webhook: Invalid amount', ['amount' => $amount]);
            return response()->json(['success' => true]);
        }

        // 3. Kiem tra trung lap - dung SP_ prefix + SePay ID de dam bao duy nhat
        $transactionId = 'SP_' . ($sepayId ?: $referenceCode);

        if ($this->depositService->isDuplicateTransaction($transactionId)) {
            Log::info('SePay webhook: Duplicate transaction', [
                'referenceCode' => $referenceCode,
            ]);
            return response()->json(['success' => true]);
        }

        // 4. Tim user - thu truong `code` truoc (SePay tu dong nhan dien), fallback sang `content`
        $userId = null;

        if (!empty($code)) {
            $userId = $this->depositService->findUserFromContent($code);
        }

        if (!$userId && !empty($content)) {
            $userId = $this->depositService->findUserFromContent($content);
        }

        // 5. Mo ta giao dich
        $description = $content ?: ($payload['description'] ?? '');

        if (!$userId) {
            // Luu pending cho admin xu ly thu cong
            $this->depositService->savePendingDeposit(
                $transactionId,
                $description,
                $transactionDate,
                $amount,
                $payload
            );

            Log::info('SePay webhook: No user found, saved for manual processing', [
                'referenceCode' => $referenceCode,
                'amount' => $amount,
                'code' => $code,
                'content' => $content,
            ]);

            return response()->json(['success' => true]);
        }

        // 6. Xu ly nap tien
        $this->depositService->processDeposit(
            $userId,
            $amount,
            $transactionId,
            $description,
            $transactionDate,
            $payload,
            'sepay'
        );

        // Luon tra success cho SePay (yeu cau cua SePay de khong retry)
        return response()->json(['success' => true]);
    }
}
