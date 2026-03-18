<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BankAuto;
use App\Models\DepositLog;
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
     * Lấy deposit logs theo bank_auto_id hoặc tid
     * GET /api/admin/bank-auto/{id}/logs
     */
    public function logs(Request $request, int $id): JsonResponse
    {
        if (!$request->user() || !$request->user()->isAdmin()) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $bankAuto = BankAuto::find($id);
        if (!$bankAuto) {
            return response()->json(['success' => false, 'message' => 'Không tìm thấy giao dịch'], 404);
        }

        // Lấy logs theo bank_auto_id hoặc tid
        $logs = DepositLog::where(function ($q) use ($bankAuto) {
                $q->where('bank_auto_id', $bankAuto->id)
                  ->orWhere('tid', $bankAuto->tid);
            })
            ->orderBy('created_at', 'asc')
            ->get(['step', 'status', 'source', 'tid', 'user_id', 'amount', 'expected_amount',
                   'deposit_code', 'bank_auto_id', 'message', 'context', 'raw_payload', 'created_at']);

        return response()->json(['success' => true, 'data' => $logs]);
    }

    /**
     * Admin duyệt giao dịch pending_duplicate hoặc pending
     * POST /api/admin/bank-auto/{id}/approve
     */
    public function approve(Request $request, int $id): JsonResponse
    {
        if (!$request->user() || !$request->user()->isAdmin()) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $bankAuto = BankAuto::find($id);

        if (!$bankAuto) {
            return response()->json(['success' => false, 'message' => 'Không tìm thấy giao dịch'], 404);
        }

        $result = $this->depositService->approveDeposit($bankAuto);

        if (!$result['success']) {
            return response()->json(['success' => false, 'message' => $result['message']], 400);
        }

        Log::info('Admin approved deposit', [
            'bank_auto_id' => $id,
            'admin_id'     => $request->user()->id,
        ]);

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
    public function reject(Request $request, int $id): JsonResponse
    {
        if (!$request->user() || !$request->user()->isAdmin()) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $bankAuto = BankAuto::find($id);

        if (!$bankAuto) {
            return response()->json(['success' => false, 'message' => 'Không tìm thấy giao dịch'], 404);
        }

        $result = $this->depositService->rejectDeposit($bankAuto, $request->input('reason', ''));

        if (!$result['success']) {
            return response()->json(['success' => false, 'message' => $result['message']], 400);
        }

        Log::info('Admin rejected deposit', [
            'bank_auto_id' => $id,
            'admin_id'     => $request->user()->id,
        ]);

        return response()->json(['success' => true, 'message' => 'Đã từ chối giao dịch']);
    }
}
