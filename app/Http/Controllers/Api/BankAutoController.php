<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BankAuto;
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
     * Admin duyệt giao dịch pending_duplicate hoặc pending
     * POST /api/admin/bank-auto/{id}/approve
     */
    public function approve(int $id): JsonResponse
    {
        $bankAuto = BankAuto::find($id);

        if (!$bankAuto) {
            return response()->json(['success' => false, 'message' => 'Không tìm thấy giao dịch'], 404);
        }

        $result = $this->depositService->approveDeposit($bankAuto);

        if (!$result['success']) {
            return response()->json(['success' => false, 'message' => $result['message']], 400);
        }

        Log::info('Admin approved deposit', ['bank_auto_id' => $id]);

        return response()->json([
            'success'     => true,
            'message'     => 'Duyệt giao dịch thành công',
            'new_balance' => $result['new_balance'],
        ]);
    }

    /**
     * Admin từ chối giao dịch pending_duplicate hoặc pending
     * POST /api/admin/bank-auto/{id}/reject
     */
    public function reject(int $id, Request $request): JsonResponse
    {
        $bankAuto = BankAuto::find($id);

        if (!$bankAuto) {
            return response()->json(['success' => false, 'message' => 'Không tìm thấy giao dịch'], 404);
        }

        $result = $this->depositService->rejectDeposit($bankAuto, $request->input('reason', ''));

        if (!$result['success']) {
            return response()->json(['success' => false, 'message' => $result['message']], 400);
        }

        Log::info('Admin rejected deposit', ['bank_auto_id' => $id]);

        return response()->json(['success' => true, 'message' => 'Đã từ chối giao dịch']);
    }
}
