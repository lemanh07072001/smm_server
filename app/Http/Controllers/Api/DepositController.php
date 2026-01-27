<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BankAuto;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DepositController extends Controller
{
    /**
     * Danh sách nạp tiền với filter, search, phân trang
     */
    public function index(Request $request): JsonResponse
    {
        $search = $request->input('search');
        $status = $request->input('status');
        $depositType = $request->input('deposit_type'); // auto, manual
        $transactionType = $request->input('transaction_type'); // PLUS, MINUS
        $userId = $request->input('user_id');
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');

        $query = BankAuto::with('user')
            ->orderBy('created_at', 'desc');

        // Search theo tid, description, note hoặc user name/email
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('tid', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%")
                  ->orWhere('note', 'like', "%{$search}%")
                  ->orWhereHas('user', function ($userQuery) use ($search) {
                      $userQuery->where('name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%");
                  });
            });
        }

        // Filter theo status
        if ($status !== null) {
            $query->where('status', $status);
        }

        // Filter theo deposit_type (auto/manual)
        if ($depositType !== null) {
            $query->where('deposit_type', $depositType);
        }

        // Filter theo transaction_type (PLUS/MINUS)
        if ($transactionType !== null) {
            $query->where('transaction_type', $transactionType);
        }

        // Filter theo user_id
        if ($userId !== null) {
            $query->where('user_id', $userId);
        }

        // Filter theo ngày bắt đầu
        if ($startDate) {
            $query->whereDate('created_at', '>=', $startDate);
        }

        // Filter theo ngày kết thúc
        if ($endDate) {
            $query->whereDate('created_at', '<=', $endDate);
        }

        // Thống kê
        $stats = BankAuto::selectRaw('
            COUNT(*) as total_count,
            SUM(CASE WHEN transaction_type = "PLUS" THEN amount ELSE 0 END) as total_deposit,
            SUM(CASE WHEN transaction_type = "MINUS" THEN ABS(amount) ELSE 0 END) as total_withdraw,
            SUM(CASE WHEN deposit_type = "auto" THEN 1 ELSE 0 END) as auto_count,
            SUM(CASE WHEN deposit_type = "manual" THEN 1 ELSE 0 END) as manual_count
        ')->first();

        // Phân trang
        $perPage = $request->get('per_page', 10);
        $deposits = $query->paginate($perPage);

        return response()->json([
            'data' => $deposits->items(),
            'current_page' => $deposits->currentPage(),
            'per_page' => $deposits->perPage(),
            'total' => $deposits->total(),
            'last_page' => $deposits->lastPage(),
            'stats' => [
                'total_count' => (int) ($stats->total_count ?? 0),
                'total_deposit' => (int) ($stats->total_deposit ?? 0),
                'total_withdraw' => (int) ($stats->total_withdraw ?? 0),
                'auto_count' => (int) ($stats->auto_count ?? 0),
                'manual_count' => (int) ($stats->manual_count ?? 0),
            ],
        ]);
    }

    /**
     * Chi tiết nạp tiền
     */
    public function show(int $id): JsonResponse
    {
        $deposit = BankAuto::with('user')->find($id);

        if (!$deposit) {
            return response()->json([
                'message' => 'Không tìm thấy giao dịch.',
            ], 404);
        }

        return response()->json([
            'data' => $deposit,
        ]);
    }
}
