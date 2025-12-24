<?php

namespace App\Http\Controllers\Api;

use App\Models\Order;
use App\Models\Service;
use App\Helpers\OrderHelper;
use Illuminate\Http\Request;
use App\Models\ProviderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use App\Services\Providers\ProviderFactory;

class OrderController extends Controller
{
    /**
     * Display a listing of orders.
     */
    public function index(Request $request): JsonResponse
    {
        $limit = $request->input('limit', 10);
        $page = $request->input('page', 1);
        $search = $request->input('search');
        $status = $request->input('status');
        $userId = $request->input('user_id');
        $serviceId = $request->input('service_id');
        $providerServiceId = $request->input('provider_service_id');
        $isFinalized = $request->input('is_finalized');
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');

        $query = Order::with(['user', 'service', 'providerService'])
            ->orderBy('created_at', 'desc');

        // Search theo link hoặc provider_order_id
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('link', 'like', "%{$search}%")
                  ->orWhere('provider_order_id', 'like', "%{$search}%")
                  ->orWhereHas('user', function ($userQuery) use ($search) {
                      $userQuery->where('name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%");
                  })
                  ->orWhereHas('service', function ($serviceQuery) use ($search) {
                      $serviceQuery->where('name', 'like', "%{$search}%");
                  });
            });
        }

        // Filter theo status
        if ($status !== null) {
            $query->where('status', $status);
        }


        // Filter theo ngày bắt đầu
        if ($startDate) {
            $query->whereDate('created_at', '>=', $startDate);
        }

        // Filter theo ngày kết thúc
        if ($endDate) {
            $query->whereDate('created_at', '<=', $endDate);
        }

        $total = $query->count();
        $totalPages = (int) ceil($total / $limit);

        $orders = $query->skip(($page - 1) * $limit)
            ->take($limit)
            ->get();

        return response()->json([
            'data' => $orders,
            'total' => $total,
            'page' => (int) $page,
            'limit' => (int) $limit,
            'totalPages' => $totalPages,
        ]);
    }

    /**
     * Lấy tất cả đơn hàng theo user_id và thống kê số lượng từng trạng thái.
     */
    public function getOrdersByUser(Request $request, int $userId): JsonResponse
    {
        $search = $request->input('search');
        $status = $request->input('status');
        $perPage = $request->input('per_page', 10);

        // Query orders của user
        $query = Order::with(['service'])
            ->where('user_id', $userId);

        // Filter theo status (nếu status là "all" thì lấy tất cả)
        if ($status !== null && $status !== 'all') {
            $query->where('status', $status);
        }

        // Tìm kiếm theo link, provider_order_id hoặc tên service
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('link', 'like', "%{$search}%")
                  ->orWhere('provider_order_id', 'like', "%{$search}%")
                  ->orWhereHas('service', function ($serviceQuery) use ($search) {
                      $serviceQuery->where('name', 'like', "%{$search}%");
                  });
            });
        }

        // Phân trang
        $page = $request->input('page', 1);
        $total = $query->count();
        $totalPages = (int) ceil($total / $perPage);

        $orders = $query->orderBy('created_at', 'desc')
            ->skip(($page - 1) * $perPage)
            ->take($perPage)
            ->get();

        // Thống kê số lượng từng trạng thái
        $statusCountsRaw = Order::where('user_id', $userId)
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        // Khởi tạo tất cả status với giá trị 0
        $statusCounts = [
            'pending' => $statusCountsRaw['pending'] ?? 0,
            'processing' => $statusCountsRaw['processing'] ?? 0,
            'in_progress' => $statusCountsRaw['in_progress'] ?? 0,
            'completed' => $statusCountsRaw['completed'] ?? 0,
            'partial' => $statusCountsRaw['partial'] ?? 0,
            'canceled' => $statusCountsRaw['canceled'] ?? 0,
            'refunded' => $statusCountsRaw['refunded'] ?? 0,
            'failed' => $statusCountsRaw['failed'] ?? 0,
        ];

        return response()->json([
            'data' => $orders,
            'total' => $total,
            'page' => (int) $page,
            'limit' => (int) $perPage,
            'totalPages' => $totalPages,
            'status_counts' => $statusCounts,
        ]);
    }

    public function addOrder(Request $request): JsonResponse
    {
        // Validate request
        $validated = $request->validate([
            'service_id' => ['required', 'integer', 'exists:services,id'],
            'provider_service_id' => ['required', 'integer', 'exists:provider_services,id'],
            'link' => ['required', 'string', 'max:1000'],
            'quantity' => ['required', 'integer', 'min:1'],
            'reactions' => ['nullable', 'array'],
        ]);

        // Lấy user từ authenticated request
        $user = $request->user();

        // Lấy Service với ProviderService và Provider (nested relationship)
        $service = Service::with(['providerService.provider'])
            ->where('provider_service_id', $validated['provider_service_id'])
            ->first();

        if (!$service) {
            return response()->json([
                'message' => 'Service không tồn tại.',
            ], 404);
        }

        // Lấy provider thông qua providerService
        $provider = $service->providerService->provider;

        if (!$provider) {
            return response()->json([
                'message' => 'Provider không tồn tại.',
            ], 404);
        }

        // Kiểm tra provider có được hỗ trợ không
        if (!ProviderFactory::isSupported($provider->code)) {
            return response()->json([
                'message' => 'Provider không được hỗ trợ: ' . $provider->code,
            ], 400);
        }

        // Tính toán số tiền
        $costRate = $service->providerService->cost_rate;
        $sellRate = $service->sell_rate;
        $quantity = $validated['quantity'];

        $costAmount = $costRate * $quantity;
        $chargeAmount = $sellRate * $quantity;
        $profitAmount = $chargeAmount - $costAmount;

        // Kiểm tra số dư của user
        $user->refresh();
        if ($user->balance < $chargeAmount) {
            return response()->json([
                'message' => 'Số dư không đủ để thực hiện đơn hàng.',
                'balance' => (float) $user->balance,
                'required' => (float) $chargeAmount,
                'shortage' => (float) ($chargeAmount - $user->balance),
            ], 400);
        }

        DB::beginTransaction();
        try {
            // Trừ tiền từ balance của user
            $user->balance -= $chargeAmount;
            $user->save();

            // Tạo order trong database với status pending
            $order = Order::create([
                'user_id' => $user->id,
                'service_id' => $validated['service_id'],
                'provider_service_id' => $validated['provider_service_id'],
                'link' => $validated['link'],
                'quantity' => $quantity,
                'status' => Order::STATUS_PENDING,
                'cost_rate' => $costRate,
                'sell_rate' => $sellRate,
                'charge_amount' => $chargeAmount,
                'cost_amount' => $costAmount,
                'profit_amount' => $profitAmount,
                'refund_amount' => 0,
                'final_charge' => 0,
                'final_cost' => 0,
                'final_profit' => 0,
                'is_finalized' => false,
            ]);

            // Load relationships
            $order->load(['user', 'service', 'providerService.provider']);

            // Đẩy order vào Redis để command order_place xử lý
            OrderHelper::saveOrderToRedis($order);

            DB::commit();

            return response()->json([
                'message' => 'Tạo đơn hàng thành công.',
                'data' => [
                    'order' => $order,
                    'new_balance' => (float) $user->balance,
                ],
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Error creating order', [
                'error' => $e->getMessage(),
                'user_id' => $user->id,
                'service_id' => $validated['service_id'],
            ]);

            return response()->json([
                'message' => 'Lỗi khi tạo đơn hàng. Vui lòng thử lại.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
