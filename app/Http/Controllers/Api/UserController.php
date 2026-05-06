<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\BankAuto;
use App\Models\Dongtien;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $limit = $request->input('limit', 10);
        $page = $request->input('page', 1);
        $search = $request->input('search');
        $status = $request->input('is_active');
        $role = $request->input('role');

        $query = User::orderBy('created_at', 'desc');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($status !== null) {
            $query->where('is_active', $status === '1' || $status === 'true' ? 1 : 0);
        }

        if ($role !== null) {
            $query->where('role', $role);
        }

        $total = $query->count();
        $totalPages = (int) ceil($total / $limit);

        $users = $query->skip(($page - 1) * $limit)
            ->take($limit)
            ->get();

        return response()->json([
            'data' => $users,
            'total' => $total,
            'page' => (int) $page,
            'limit' => (int) $limit,
            'totalPages' => $totalPages,
        ]);
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        if (!$request->user()->isAdmin()) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $data = $request->validated();
        $data['password'] = Hash::make($data['password']);

        $role = (int) ($data['role'] ?? User::ROLE_USER);
        if ($role === User::ROLE_TAX) {
            $data['tax_percent'] = $data['tax_percent'] ?? 10;
        } else {
            $data['tax_percent'] = null;
        }

        $user = User::create($data);

        return response()->json([
            'message' => 'Tạo người dùng thành công.',
            'data' => $user,
        ], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        if (!$request->user()->isAdmin()) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $user = User::findOrFail($id);

        return response()->json([
            'data' => $user,
        ]);
    }

    public function update(UpdateUserRequest $request, int $id): JsonResponse
    {
        if (!$request->user()->isAdmin()) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $user = User::findOrFail($id);
        $data = $request->validated();

        // Hash password if provided
        if (!empty($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }

        $newRole = array_key_exists('role', $data) ? (int) $data['role'] : (int) $user->role;
        if ($newRole === User::ROLE_TAX) {
            if (!array_key_exists('tax_percent', $data) && $user->tax_percent === null) {
                $data['tax_percent'] = 10;
            }
        } else {
            $data['tax_percent'] = null;
        }

        $user->update($data);

        return response()->json([
            'message' => 'Cập nhật người dùng thành công.',
            'data' => $user,
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        if (!$request->user()->isAdmin()) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $user = User::findOrFail($id);
        $user->delete();

        return response()->json([
            'message' => 'Xóa người dùng thành công.',
        ]);
    }

    public function destroyMultiple(Request $request): JsonResponse
    {
        if (!$request->user()->isAdmin()) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'integer', 'exists:users,id'],
        ]);

        $count = User::whereIn('id', $request->ids)->delete();

        return response()->json([
            'message' => "Đã xóa {$count} người dùng thành công.",
        ]);
    }

    public function resetPassword(Request $request, int $id): JsonResponse
    {
        if (!$request->user()->isAdmin()) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $request->validate([
            'password' => ['required', 'string', 'min:6'],
        ], [
            'password.required' => 'Mật khẩu mới là bắt buộc.',
            'password.min' => 'Mật khẩu phải có ít nhất 6 ký tự.',
        ]);

        $user = User::findOrFail($id);
        $user->update([
            'password' => Hash::make($request->password),
        ]);

        return response()->json([
            'message' => 'Đặt lại mật khẩu thành công.',
        ]);
    }

    public function generateApiKey(Request $request, int $id): JsonResponse
    {
        if (!$request->user()->isAdmin()) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $user = User::findOrFail($id);
        $apiKey = Str::random(64);

        $user->update([
            'api_key' => $apiKey,
        ]);

        return response()->json([
            'message' => 'Tạo API key thành công.',
            'data' => [
                'api_key' => $apiKey,
            ],
        ]);
    }

    /**
     * User tự generate lại api_token (lưu vào DB, không ảnh hưởng login)
     */
    public function generateMyApiKey(Request $request): JsonResponse
    {
        $user = $request->user();

        // Xóa sanctum token api cũ nếu có
        $user->tokens()->where('name', 'api_token')->delete();
        // Tạo Sanctum token và lưu plaintext vào cột api_token
        $apiTokenPlain = $user->createToken('api_token')->plainTextToken;
        $user->update(['api_token' => $apiTokenPlain]);

        return response()->json([
            'message' => 'Tạo API token thành công.',
            'data' => [
                'api_token' => $apiTokenPlain,
            ],
        ]);
    }

    /**
     * Xem api_token hiện tại
     */
    public function getMyApiKey(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'data' => [
                'api_token' => $user->api_token,
            ],
        ]);
    }

    /**
     * Cộng hoặc trừ tiền cho user
     * amount: luôn dương
     * type: deposit/refund = cộng tiền, charge = trừ tiền, adjustment = dùng is_credit
     */
    public function adjustBalance(Request $request, int $id): JsonResponse
    {
        if (!$request->user()->isAdmin()) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'type' => ['required', 'string', 'in:deposit,charge,refund,adjustment,withdraw'],
            'is_credit' => ['nullable', 'boolean'],
            'payment_method' => ['nullable', 'string', 'max:50'],
            'payment_ref' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:500'],
        ], [
            'amount.required' => 'Số tiền là bắt buộc.',
            'amount.numeric' => 'Số tiền phải là số.',
            'amount.gt' => 'Số tiền phải lớn hơn 0.',
            'type.required' => 'Loại giao dịch là bắt buộc.',
            'type.in' => 'Loại giao dịch phải là: deposit, charge, refund, adjustment, withdraw.',
        ]);

        $user = User::findOrFail($id);
        $amount = (float) $request->amount;
        $type = $request->type;

        // Xác định đây là giao dịch cộng hay trừ
        $isCredit = match ($type) {
            Dongtien::TYPE_DEPOSIT, Dongtien::TYPE_REFUND => true,
            Dongtien::TYPE_CHARGE, 'withdraw' => false,
            default => $request->input('is_credit', true), // adjustment mặc định là cộng
        };

        // Nếu trừ tiền, kiểm tra số dư
        if (!$isCredit && $user->balance < $amount) {
            return response()->json([
                'message' => 'Số dư không đủ để trừ.',
                'data' => [
                    'current_balance' => $user->balance,
                    'amount_requested' => $amount,
                ],
            ], 422);
        }

        $note = $request->note ?? ($isCredit ? 'CTV cộng tiền' : 'CTV trừ tiền');

        $dongtien = Dongtien::createTransaction(
            $user,
            $amount,
            $type,
            $note,
            [
                'is_credit' => $isCredit,
                'payment_method' => $request->payment_method,
                'payment_ref' => $request->payment_ref,
            ]
        );

        // Tạo record bank_auto khi cộng/trừ tiền thủ công
        if ($type === Dongtien::TYPE_DEPOSIT || $type === 'withdraw') {
            BankAuto::create([
                'tid' => 'MANUAL_' . time() . '_' . $user->id,
                'description' => $note,
                'date' => now()->format('d/m/Y'),
                'data' => json_encode([
                    'admin_id' => $request->user()->id,
                    'admin_name' => $request->user()->name,
                ]),
                'amount' => $isCredit ? (int) $amount : (int) -$amount,
                'user_id' => $user->id,
                'transaction_type' => $isCredit ? 'PLUS' : 'MINUS',
                'type' => 'bank',
                'status' => 'success',
                'note' => $note,
                'deposit_type' => 'manual',
            ]);
        }

        return response()->json([
            'message' => $isCredit ? 'Cộng tiền thành công.' : 'Trừ tiền thành công.',
            'data' => [
                'user_id' => $user->id,
                'balance_before' => $dongtien->balance_before,
                'amount' => $amount,
                'type' => $type,
                'is_credit' => $isCredit,
                'payment_method' => $dongtien->payment_method,
                'payment_ref' => $dongtien->payment_ref,
                'balance_after' => $dongtien->balance_after,
                'note' => $note,
            ],
        ]);
    }
}
