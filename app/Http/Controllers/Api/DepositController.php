<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BankAuto;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

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
     * Huỷ giao dịch pending
     */
    public function cancel(int $id): JsonResponse
    {
        $user = Auth::user();
        $deposit = BankAuto::where('id', $id)
            ->where('user_id', $user->id)
            ->where('status', 'pending')
            ->first();

        if (!$deposit) {
            return response()->json(['message' => 'Không tìm thấy giao dịch.'], 404);
        }

        $deposit->update(['status' => 'cancelled']);

        return response()->json(['message' => 'Đã huỷ giao dịch.']);
    }

    /**
     * Lấy giao dịch pending còn hạn của user hiện tại
     */
    public function currentPending(): JsonResponse
    {
        $user = Auth::user();
        $deposit = BankAuto::where('user_id', $user->id)
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->latest()
            ->first();

        return response()->json(['data' => $deposit]);
    }

    /**
     * Tạo bản ghi pending khi user tạo QR thanh toán
     */
    public function createPending(Request $request): JsonResponse
    {
        $request->validate(['amount' => 'required|integer|min:50000']);

        $user = Auth::user();

        $deposit = BankAuto::create([
            'user_id'          => $user->id,
            'amount'           => $request->amount,
            'description'      => $user->deposit_code,
            'transaction_type' => 'PLUS',
            'deposit_type'     => 'auto',
            'status'           => 'pending',
            'expires_at'       => now()->addMinutes(5),
        ]);

        return response()->json(['data' => $deposit], 201);
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
