<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Dongtien;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class DongtienController extends Controller
{
    /**
     * Lấy danh sách lịch sử giao dịch của user đăng nhập
     */
    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $query = Dongtien::where('user_id', $userId)
            ->select(['id', 'balance_before', 'amount', 'balance_after', 'type', 'thoigian', 'noidung', 'payment_method']);

        // Filter theo type: deposit, charge, refund, adjustment
        if ($request->has('type') && in_array($request->type, ['deposit', 'charge', 'refund', 'adjustment','withdraw'])) {
            $query->where('type', $request->type);
        }

        // Sắp xếp mới nhất trước
        $query->orderBy('id', 'desc');

        // Phân trang
        $perPage = $request->get('per_page', 9);
        $transactions = $query->paginate($perPage);

        return response()->json($transactions);
    }

    /**
     * [Admin] Danh sách user kèm tổng nạp
     * GET /admin/users/deposits?search=&from=&to=&per_page=
     */
    public function adminUserDeposits(Request $request): JsonResponse
    {
        if (!$request->user()->isAdmin()) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $query = User::select('users.id', 'users.name', 'users.email', 'users.balance', 'users.created_at')
            ->selectSub(
                DB::table('dongtien')
                    ->selectRaw('COALESCE(SUM(amount), 0)')
                    ->whereColumn('user_id', 'users.id')
                    ->where('type', Dongtien::TYPE_DEPOSIT)
                    ->when($request->from, fn($q) => $q->where('thoigian', '>=', $request->from))
                    ->when($request->to,   fn($q) => $q->where('thoigian', '<=', $request->to . ' 23:59:59')),
                'total_deposit'
            )
            ->selectSub(
                DB::table('dongtien')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('user_id', 'users.id')
                    ->where('type', Dongtien::TYPE_DEPOSIT)
                    ->when($request->from, fn($q) => $q->where('thoigian', '>=', $request->from))
                    ->when($request->to,   fn($q) => $q->where('thoigian', '<=', $request->to . ' 23:59:59')),
                'deposit_count'
            )
            ->selectSub(
                DB::table('dongtien')
                    ->selectRaw('MAX(thoigian)')
                    ->whereColumn('user_id', 'users.id')
                    ->where('type', Dongtien::TYPE_DEPOSIT),
                'last_deposit_at'
            );

        if ($request->search) {
            $s = $request->search;
            $query->where(fn($q) => $q->where('users.name', 'like', "%{$s}%")
                                      ->orWhere('users.email', 'like', "%{$s}%"));
        }

        $query->orderByDesc('total_deposit');

        $perPage = $request->get('per_page', 20);
        $result  = $query->paginate($perPage);

        return response()->json($result);
    }

    /**
     * [Admin] Lịch sử nạp tiền của 1 user
     * GET /admin/users/{id}/deposits?from=&to=&per_page=
     */
    public function adminUserDepositHistory(Request $request, int $id): JsonResponse
    {
        if (!$request->user()->isAdmin()) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $user = User::findOrFail($id);

        $query = Dongtien::where('user_id', $id)
            ->where('type', Dongtien::TYPE_DEPOSIT)
            ->select(['id', 'amount', 'balance_before', 'balance_after', 'payment_method', 'payment_ref', 'noidung', 'thoigian'])
            ->when($request->from, fn($q) => $q->where('thoigian', '>=', $request->from))
            ->when($request->to,   fn($q) => $q->where('thoigian', '<=', $request->to . ' 23:59:59'))
            ->orderByDesc('id');

        $perPage      = $request->get('per_page', 20);
        $transactions = $query->paginate($perPage);

        return response()->json([
            'user' => [
                'id'      => $user->id,
                'name'    => $user->name,
                'email'   => $user->email,
                'balance' => $user->balance,
            ],
            'total_deposit' => Dongtien::where('user_id', $id)->where('type', Dongtien::TYPE_DEPOSIT)->sum('amount'),
            'transactions'  => $transactions,
        ]);
    }

}
