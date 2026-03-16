<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AffiliateCommission;
use App\Models\AffiliateWithdrawal;
use App\Models\Dongtien;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class AffiliateController extends Controller
{
    /**
     * Dashboard affiliate - thống kê tổng quan
     */
    public function dashboard(Request $request): JsonResponse
    {
        $user = $request->user();
        $cacheKey = "affiliate_dashboard_{$user->id}";

        $stats = Cache::remember($cacheKey, 60, function () use ($user) {
            $commissionTotal = AffiliateCommission::where('user_id', $user->id)
                ->sum('commission_amount');

            $referralsCount = User::where('referred_by', $user->id)->count();

            $withdrawalStats = AffiliateWithdrawal::where('user_id', $user->id)
                ->selectRaw("
                    SUM(CASE WHEN status = ? THEN amount ELSE 0 END) as total_withdrawn,
                    SUM(CASE WHEN status = ? THEN amount ELSE 0 END) as pending_withdrawal
                ", [AffiliateWithdrawal::STATUS_APPROVED, AffiliateWithdrawal::STATUS_PENDING])
                ->first();

            return [
                'total_commission'  => (float) $commissionTotal,
                'total_referrals'   => $referralsCount,
                'total_withdrawn'   => (float) ($withdrawalStats->total_withdrawn ?? 0),
                'pending_withdrawal' => (float) ($withdrawalStats->pending_withdrawal ?? 0),
            ];
        });

        return response()->json([
            'affiliate_balance'  => (float) $user->affiliate_balance,
            'total_commission'   => $stats['total_commission'],
            'total_referrals'    => $stats['total_referrals'],
            'total_withdrawn'    => $stats['total_withdrawn'],
            'pending_withdrawal' => $stats['pending_withdrawal'],
        ]);
    }

    /**
     * Lịch sử hoa hồng
     */
    public function commissions(Request $request): JsonResponse
    {
        $user = $request->user();
        $perPage = $request->input('per_page', 15);

        $commissions = AffiliateCommission::where('user_id', $user->id)
            ->with(['referredUser:id,name,email', 'order:id,charge_amount,status,created_at'])
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return response()->json($commissions);
    }

    /**
     * Danh sách người được giới thiệu
     */
    public function referrals(Request $request): JsonResponse
    {
        $user = $request->user();
        $perPage = $request->input('per_page', 15);

        $referrals = User::where('referred_by', $user->id)
            ->select('id', 'name', 'email', 'created_at')
            ->withCount('orders')
            ->withSum('orders as total_order_amount', 'charge_amount')
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return response()->json($referrals);
    }

    /**
     * Lấy link giới thiệu
     */
    public function getLink(Request $request): JsonResponse
    {
        $user = $request->user();

        $frontendUrl = rtrim(config('app.frontend_url', config('app.url')), '/');

        return response()->json([
            'ref_id' => $user->id,
            'ref_param' => '?ref=' . $user->id,
            'affiliate_link' => $frontendUrl . '/register?ref=' . $user->id,
            'commission_rate' => (float) ($user->affiliate_commission_rate ?? 10.00),
        ]);
    }

    /**
     * Yêu cầu rút tiền affiliate
     */
    public function withdraw(Request $request): JsonResponse
    {
        $request->validate([
            'amount' => ['required', 'numeric', 'min:1'],
        ]);

        $user = $request->user();
        $amount = (float) $request->amount;

        if ($amount > (float) $user->affiliate_balance) {
            return response()->json([
                'message' => 'Số dư affiliate không đủ.',
                'affiliate_balance' => (float) $user->affiliate_balance,
            ], 400);
        }

        // Kiểm tra có yêu cầu rút đang pending không
        $pendingExists = AffiliateWithdrawal::where('user_id', $user->id)
            ->where('status', AffiliateWithdrawal::STATUS_PENDING)
            ->exists();

        if ($pendingExists) {
            return response()->json([
                'message' => 'Bạn đang có yêu cầu rút tiền chờ duyệt.',
            ], 400);
        }

        $withdrawal = AffiliateWithdrawal::create([
            'user_id' => $user->id,
            'amount' => $amount,
            'status' => AffiliateWithdrawal::STATUS_PENDING,
        ]);

        Cache::forget("affiliate_dashboard_{$user->id}");

        return response()->json([
            'message' => 'Yêu cầu rút tiền đã được gửi, chờ admin duyệt.',
            'withdrawal' => $withdrawal,
        ], 201);
    }

    /**
     * Lịch sử rút tiền
     */
    public function withdrawals(Request $request): JsonResponse
    {
        $user = $request->user();
        $perPage = $request->input('per_page', 15);

        $withdrawals = AffiliateWithdrawal::where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return response()->json($withdrawals);
    }

    // ==================== Admin Endpoints ====================

    /**
     * [Admin] Cập nhật % hoa hồng cho user
     */
    public function setCommissionRate(Request $request, int $userId): JsonResponse
    {
        if (!$request->user()->isAdmin()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $request->validate([
            'commission_rate' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);

        $user = User::findOrFail($userId);
        $user->update(['affiliate_commission_rate' => $request->commission_rate]);

        return response()->json([
            'message' => 'Đã cập nhật % hoa hồng.',
            'user_id' => $user->id,
            'affiliate_commission_rate' => (float) $user->affiliate_commission_rate,
        ]);
    }

    /**
     * [Admin] Danh sách yêu cầu rút tiền
     */
    public function adminWithdrawals(Request $request): JsonResponse
    {
        if (!$request->user()->isAdmin()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $perPage = $request->input('per_page', 15);
        $status = $request->input('status');

        $query = AffiliateWithdrawal::with('user:id,name,email,affiliate_balance')
            ->orderByDesc('created_at');

        if ($status) {
            $query->where('status', $status);
        }

        return response()->json($query->paginate($perPage));
    }

    /**
     * [Admin] Duyệt rút tiền - chuyển từ ví affiliate sang balance chính
     */
    public function approve(Request $request, int $id): JsonResponse
    {
        if (!$request->user()->isAdmin()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $withdrawal = AffiliateWithdrawal::findOrFail($id);

        if (!$withdrawal->isPending()) {
            return response()->json(['message' => 'Yêu cầu này đã được xử lý.'], 400);
        }

        DB::beginTransaction();
        try {
            // Lock withdrawal để tránh 2 admin duyệt cùng lúc
            $withdrawal = AffiliateWithdrawal::lockForUpdate()->find($id);
            if (!$withdrawal || !$withdrawal->isPending()) {
                DB::rollBack();
                return response()->json(['message' => 'Yêu cầu này đã được xử lý.'], 400);
            }

            // Lock user để tránh race condition khi trừ balance
            $user = User::lockForUpdate()->find($withdrawal->user_id);
            if (!$user) {
                DB::rollBack();
                return response()->json(['message' => 'User không tồn tại.'], 404);
            }

            if ((float) $user->affiliate_balance < (float) $withdrawal->amount) {
                DB::rollBack();
                return response()->json([
                    'message' => 'Số dư affiliate không đủ.',
                    'affiliate_balance' => (float) $user->affiliate_balance,
                    'withdrawal_amount' => (float) $withdrawal->amount,
                ], 400);
            }

            // Trừ ví affiliate
            $user->decrement('affiliate_balance', $withdrawal->amount);

            // Cộng vào balance chính qua Dongtien
            $user->refresh();
            Dongtien::createTransaction(
                $user,
                $withdrawal->amount,
                Dongtien::TYPE_DEPOSIT,
                "Rút tiền affiliate #{$withdrawal->id}",
                [
                    'payment_method' => 'affiliate',
                    'is_payment_affiliate' => 1,
                ]
            );

            // Cập nhật withdrawal
            $withdrawal->update([
                'status' => AffiliateWithdrawal::STATUS_APPROVED,
                'admin_note' => $request->input('admin_note'),
                'processed_at' => now(),
                'processed_by' => $request->user()->id,
            ]);

            DB::commit();

            Cache::forget("affiliate_dashboard_{$user->id}");

            $user->refresh();
            return response()->json([
                'message' => 'Đã duyệt rút tiền thành công.',
                'withdrawal' => $withdrawal->fresh(),
                'new_balance' => (float) $user->balance,
                'new_affiliate_balance' => (float) $user->affiliate_balance,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Lỗi xử lý: ' . $e->getMessage()], 500);
        }
    }

    /**
     * [Admin] Từ chối rút tiền
     */
    public function reject(Request $request, int $id): JsonResponse
    {
        if (!$request->user()->isAdmin()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $withdrawal = AffiliateWithdrawal::findOrFail($id);

        if (!$withdrawal->isPending()) {
            return response()->json(['message' => 'Yêu cầu này đã được xử lý.'], 400);
        }

        $withdrawal->update([
            'status' => AffiliateWithdrawal::STATUS_REJECTED,
            'admin_note' => $request->input('admin_note'),
            'processed_at' => now(),
            'processed_by' => $request->user()->id,
        ]);

        Cache::forget("affiliate_dashboard_{$withdrawal->user_id}");

        return response()->json([
            'message' => 'Đã từ chối yêu cầu rút tiền.',
            'withdrawal' => $withdrawal->fresh(),
        ]);
    }
}
