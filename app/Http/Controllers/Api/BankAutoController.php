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

        // Lấy toàn bộ logs liên quan: theo tid (bao gồm cả bước đầu chưa có bank_auto_id)
        // hoặc theo bank_auto_id (cho các bước sau khi đã tạo record)
        $query = DepositLog::where(function ($q) use ($bankAuto) {
            if ($bankAuto->tid) {
                $q->where('tid', $bankAuto->tid);
            }
            $q->orWhere('bank_auto_id', $bankAuto->id);
        })->orderBy('created_at', 'asc');

        $logs = $query->get()->map(function ($log) {
            return [
                'step'             => $log->step,
                'status'           => $log->status,
                'source'           => $log->source,
                'tid'              => $log->tid,
                'user_id'          => $log->user_id,
                'amount'           => $log->amount,
                'expected_amount'  => $log->expected_amount,
                'deposit_code'     => $log->deposit_code,
                'bank_auto_id'     => $log->bank_auto_id,
                'message'          => $log->message,
                'context'          => $log->context,
                'raw_payload'      => $log->raw_payload,
                'created_at'       => $log->created_at,
            ];
        });

        return response()->json([
            'success' => true,
            'tid'     => $bankAuto->tid,
            'total'   => $logs->count(),
            'data'    => $logs,
        ]);
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
