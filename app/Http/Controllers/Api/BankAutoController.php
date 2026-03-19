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
     * Admin cộng tiền thủ công cho BankAuto cụ thể.
     * POST /api/admin/bank-auto/{id}/manual-credit
     *
     * Bước 1 (controller):
     *   - is_processed = true  → 400 "Đã xử lý rồi"
     *   - amount <= 0          → 400 "Số tiền không hợp lệ"
     */
    public function manualCredit(Request $request, int $id): JsonResponse
    {
        if (!$request->user() || !$request->user()->isAdmin()) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $bankAuto = BankAuto::find($id);
        if (!$bankAuto) {
            return response()->json(['success' => false, 'message' => 'Không tìm thấy giao dịch'], 404);
        }

        // Bước 1: status=success → không cộng được
        if ($bankAuto->status === 'success') {
            return response()->json(['success' => false, 'message' => 'Giao dịch đã thành công, không thể cộng lại'], 400);
        }

        // Bước 1: kiểm tra amount
        if ($bankAuto->amount <= 0) {
            return response()->json(['success' => false, 'message' => 'Số tiền không hợp lệ'], 400);
        }

        $request->validate([
            'admin_note' => 'nullable|string|max:500',
        ]);

        $result = $this->depositService->manualCredit(
            $bankAuto,
            $request->input('admin_note', 'Admin cộng tiền thủ công'),
            $request->user()->id
        );

        if (!$result['success']) {
            return response()->json(['success' => false, 'message' => $result['message']], 400);
        }

        return response()->json([
            'success'      => true,
            'message'      => 'Đã cộng tiền thành công',
            'bank_auto_id' => $result['bank_auto_id'],
            'tid'          => $result['tid'],
            'new_balance'  => $result['new_balance'],
        ]);
    }

    /**
     * Admin cộng tiền thủ công cho giao dịch pending/expired
     * POST /api/admin/bank-auto/{id}/credit
     */
    public function credit(Request $request, int $id): JsonResponse
    {
        if (!$request->user() || !$request->user()->isAdmin()) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $request->validate([
            'tid'        => 'nullable|string|max:100',
            'admin_note' => 'nullable|string|max:500',
        ]);

        $bankAuto = BankAuto::find($id);
        if (!$bankAuto) {
            return response()->json(['success' => false, 'message' => 'Không tìm thấy giao dịch'], 404);
        }

        $result = $this->depositService->creditDeposit(
            $bankAuto,
            $request->input('tid', ''),
            $request->input('admin_note', 'Admin xác nhận đã nhận tiền'),
            $request->user()->id
        );

        if (!$result['success']) {
            return response()->json(['success' => false, 'message' => $result['message']], 400);
        }

        Log::info('Admin credited deposit manually', [
            'bank_auto_id' => $id,
            'admin_id'     => $request->user()->id,
            'tid'          => $request->input('tid'),
        ]);

        return response()->json([
            'success'     => true,
            'message'     => 'Đã cộng tiền thành công',
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
