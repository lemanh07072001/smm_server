<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Dongtien;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

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
        if ($request->has('type') && in_array($request->type, ['deposit', 'charge', 'refund', 'adjustment'])) {
            $query->where('type', $request->type);
        }

        // Sắp xếp mới nhất trước
        $query->orderBy('id', 'desc');

        // Phân trang
        $perPage = $request->get('per_page', 5);
        $transactions = $query->paginate($perPage);

        return response()->json($transactions);
    }

}
