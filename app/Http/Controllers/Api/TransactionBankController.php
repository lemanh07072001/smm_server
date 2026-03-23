<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TransactionBank;
use App\Services\DepositService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TransactionBankController extends Controller
{
    public function __construct(private DepositService $depositService) {}

    /**
     * Danh sách transaction_banks cho admin.
     * GET /api/admin/transaction-banks
     */
    public function index(Request $request): JsonResponse
    {
        if (!$request->user()?->isAdmin()) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $query = TransactionBank::orderBy('created_at', 'desc');

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('tid', 'like', "%{$search}%")
                  ->orWhere('content', 'like', "%{$search}%")
                  ->orWhere('account_number', 'like', "%{$search}%");
            });
        }

        if ($request->filled('source') && $request->input('source') !== 'all') {
            $query->where('source', $request->input('source'));
        }

        if ($request->filled('is_processed') && $request->input('is_processed') !== 'all') {
            $query->where('is_processed', $request->boolean('is_processed'));
        }

        if ($request->filled('start_date')) {
            $query->whereDate('created_at', '>=', $request->input('start_date'));
        }

        if ($request->filled('end_date')) {
            $query->whereDate('created_at', '<=', $request->input('end_date'));
        }

        $perPage = (int) $request->input('per_page', 20);
        $result  = $query->paginate($perPage);

        return response()->json([
            'data'      => $result->items(),
            'total'     => $result->total(),
            'last_page' => $result->lastPage(),
        ]);
    }

    /**
     * Admin cộng tiền thủ công từ TransactionBank.
     * POST /api/admin/transaction-banks/{id}/manual-credit
     *
     * Validate:
     *   - is_processed = true  → 400
     *   - transfer_type != IN  → 400
     *   - amount <= 0          → 400
     */
    public function manualCredit(Request $request, int $id): JsonResponse
    {
        if (!$request->user()?->isAdmin()) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $txn = TransactionBank::find($id);
        if (!$txn) {
            return response()->json(['success' => false, 'message' => 'Không tìm thấy giao dịch'], 404);
        }

        if ($txn->is_processed) {
            return response()->json(['success' => false, 'message' => 'Đã xử lý rồi'], 400);
        }

        if ($txn->transfer_type !== 'IN') {
            return response()->json(['success' => false, 'message' => 'Chỉ xử lý giao dịch chuyển vào (IN)'], 400);
        }

        if ($txn->amount <= 0) {
            return response()->json(['success' => false, 'message' => 'Số tiền không hợp lệ'], 400);
        }

        $request->validate([
            'user_id'    => 'required|integer|exists:users,id',
            'admin_note' => 'nullable|string|max:500',
        ]);

        $result = $this->depositService->manualCreditFromTransaction(
            $txn,
            (int) $request->input('user_id'),
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
}
