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
     * Admin: danh sách lệnh nạp tiền (BankAuto) — dùng riêng cho admin
     * GET /api/admin/deposits
     */
    public function adminIndex(Request $request): JsonResponse
    {
        if (!$request->user() || !$request->user()->isAdmin()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $search      = $request->input('search');
        $status      = $request->input('status');
        $depositType = $request->input('deposit_type');
        $channel     = $request->input('payment_channel');

        $query = BankAuto::with('user')->orderBy('created_at', 'desc');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('tid', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%")
                  ->orWhere('transaction_code', 'like', "%{$search}%")
                  ->orWhereHas('user', fn($u) => $u->where('email', 'like', "%{$search}%")
                                                    ->orWhere('name', 'like', "%{$search}%"));
                if (is_numeric($search)) {
                    $q->orWhere('user_id', (int) $search)
                      ->orWhere('amount', (int) $search);
                }
            });
        }
        if ($status && $status !== 'all')      $query->where('status', $status);
        if ($depositType && $depositType !== 'all') $query->where('deposit_type', $depositType);
        if ($channel && $channel !== 'all')    $query->where('payment_channel', $channel);

        $perPage  = (int) $request->get('per_page', 20);
        $deposits = $query->paginate($perPage);

        $stats = \DB::table('bank_auto')->selectRaw('
            COUNT(*) as total_count,
            SUM(CASE WHEN status = "success" THEN amount ELSE 0 END) as total_success,
            SUM(CASE WHEN status = "pending" THEN 1 ELSE 0 END) as pending_count,
            SUM(CASE WHEN deposit_type = "auto" THEN 1 ELSE 0 END) as auto_count,
            SUM(CASE WHEN deposit_type = "manual" THEN 1 ELSE 0 END) as manual_count
        ')->first();

        return response()->json([
            'data'         => $deposits->items(),
            'current_page' => $deposits->currentPage(),
            'per_page'     => $deposits->perPage(),
            'total'        => $deposits->total(),
            'last_page'    => $deposits->lastPage(),
            'stats'        => [
                'total_count'   => (int) ($stats->total_count ?? 0),
                'total_success' => (int) ($stats->total_success ?? 0),
                'pending_count' => (int) ($stats->pending_count ?? 0),
                'auto_count'    => (int) ($stats->auto_count ?? 0),
                'manual_count'  => (int) ($stats->manual_count ?? 0),
            ],
        ]);
    }

    /**
     * Admin: nhật ký webhook (DepositLog MongoDB)
     * GET /api/admin/webhook-logs
     */
    public function webhookLogs(Request $request): JsonResponse
    {
        if (!$request->user() || !$request->user()->isAdmin()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $search  = $request->input('search');
        $status  = $request->input('status');
        $source  = $request->input('source');
        $step    = $request->input('step');

        $query = \App\Models\DepositLog::orderBy('created_at', 'desc');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('tid', 'like', "%{$search}%")
                  ->orWhere('message', 'like', "%{$search}%")
                  ->orWhere('deposit_code', 'like', "%{$search}%");
            });
        }
        if ($status && $status !== 'all') $query->where('status', $status);
        if ($source && $source !== 'all') $query->where('source', $source);
        if ($step   && $step   !== 'all') $query->where('step', $step);

        $perPage = (int) $request->get('per_page', 30);
        $logs    = $query->paginate($perPage);

        return response()->json([
            'data'         => $logs->items(),
            'current_page' => $logs->currentPage(),
            'per_page'     => $logs->perPage(),
            'total'        => $logs->total(),
            'last_page'    => $logs->lastPage(),
        ]);
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
        $request->validate(['amount' => 'required|integer|min:2000']);

        $user = Auth::user();

        $existing = BankAuto::where('user_id', $user->id)
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->whereNull('tid')
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'Bạn đang có giao dịch chờ thanh toán. Vui lòng hủy trước khi tạo mới.',
                'data'    => $existing,
            ], 409);
        }

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

    /**
     * Tra cứu toàn bộ timeline của một TID.
     * GET /api/admin/deposits/trace?tid={tid}
     */
    public function traceByTid(Request $request): JsonResponse
    {
        if (!$request->user() || !$request->user()->isAdmin()) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $tid = trim($request->query('tid', ''));
        if (!$tid) {
            return response()->json(['success' => false, 'message' => 'Thiếu tham số tid'], 400);
        }

        // 1. TransactionBank (raw webhook)
        $txn = \App\Models\TransactionBank::where('tid', $tid)->first();

        // 2. BankAuto (lệnh nạp được match)
        $bankAuto = \App\Models\BankAuto::where('tid', $tid)
            ->orWhere('transaction_code', $tid)
            ->with('user:id,name,email,balance')
            ->first();

        // Nếu không tìm theo tid thì thử tìm TransactionBank qua bank_auto_id
        if (!$bankAuto && $txn?->bank_auto_id) {
            $bankAuto = \App\Models\BankAuto::where('id', $txn->bank_auto_id)
                ->with('user:id,name,email,balance')
                ->first();
        }

        // 3. Dongtien (đã cộng tiền chưa)
        $dongtien = null;
        if ($bankAuto) {
            $dongtien = \App\Models\Dongtien::where('bank_auto_id', $bankAuto->id)
                ->select('id', 'user_id', 'amount', 'balance_before', 'balance_after', 'type', 'noidung', 'thoigian', 'created_at')
                ->first();
        }

        // 4. Deposit logs (MongoDB)
        $logs = collect();
        try {
            $query = \App\Models\DepositLog::where(function ($q) use ($tid, $bankAuto) {
                $q->where('tid', $tid);
                if ($bankAuto) {
                    $q->orWhere('bank_auto_id', $bankAuto->id);
                }
            })->orderBy('created_at', 'asc')->get();
            $logs = $query;
        } catch (\Exception $e) {
            // MongoDB unavailable
        }

        $found = $txn || $bankAuto || $logs->isNotEmpty();

        return response()->json([
            'success'      => true,
            'tid'          => $tid,
            'found'        => $found,
            'transaction_bank' => $txn,
            'bank_auto'    => $bankAuto,
            'dongtien'     => $dongtien,
            'logs'         => $logs->map(fn($l) => [
                'step'         => $l->step,
                'status'       => $l->status,
                'source'       => $l->source,
                'tid'          => $l->tid,
                'user_id'      => $l->user_id,
                'amount'       => $l->amount,
                'bank_auto_id' => $l->bank_auto_id,
                'deposit_code' => $l->deposit_code,
                'message'      => $l->message,
                'context'      => $l->context,
                'raw_payload'  => $l->raw_payload,
                'created_at'   => $l->created_at,
            ])->values(),
        ]);
    }
}
