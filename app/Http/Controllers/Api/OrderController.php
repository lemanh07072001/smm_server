<?php

namespace App\Http\Controllers\Api;

use App\Models\Order;
use App\Models\Service;
use App\Models\Dongtien;
use App\Models\ReportOrderDaily;
use App\Helpers\OrderHelper;
use App\Helpers\OrderActivityLogger;
use App\Helpers\TelegramHelper;
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
        $query = Order::with(['service','user'])
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
            'comments' => ['nullable', 'string'],
        ]);

        // Convert newline thực thành literal \n để lưu DB
        if (!empty($validated['comments'])) {
            $validated['comments'] = str_replace(["\r\n", "\r", "\n"], '\n', $validated['comments']);
        }

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
            // Tạo order trong database với status pending
            $order = Order::create([
                'user_id' => $user->id,
                'service_id' => $validated['service_id'],
                'provider_service_id' => $validated['provider_service_id'],
                'link' => $validated['link'],
                'quantity' => $quantity,
                'comments' => $validated['comments'] ?? null,
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

            // Trừ tiền và ghi vào bảng dongtien
            Dongtien::createTransaction(
                $user,
                $chargeAmount,
                Dongtien::TYPE_CHARGE,
                "Mua dịch vụ #{$service->id} - {$service->name}",
                ['order_id' => $order->id]
            );

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

    /**
     * Lấy thống kê đơn hàng theo user_id từ report_order_daily.
     */
    public function getStatsByUser(Request $request, int $userId): JsonResponse
    {
        $fromDate = $request->input('from_date');
        $toDate = $request->input('to_date');

        $query = ReportOrderDaily::where('user_id', $userId);

        if ($fromDate) {
            $query->where('date_at', '>=', (int) $fromDate);
        }

        if ($toDate) {
            $query->where('date_at', '<=', (int) $toDate);
        }

        $stats = $query->selectRaw('
            SUM(order_pending) as total_pending,
            SUM(order_processing) as total_processing,
            SUM(order_in_progress) as total_in_progress,
            SUM(order_completed) as total_completed,
            SUM(order_partial) as total_partial,
            SUM(order_canceled) as total_canceled,
            SUM(order_refunded) as total_refunded,
            SUM(order_failed) as total_failed,
            SUM(order_pending + order_processing + order_in_progress + order_completed + order_partial + order_canceled + order_refunded + order_failed) as total_orders,
            SUM(total_charge) as total_spent,
            SUM(total_cost) as total_cost,
            SUM(total_profit) as total_profit,
            SUM(total_refund) as total_refund,
            SUM(total_quantity) as total_quantity
        ')->first();

        return response()->json([
            'user_id' => $userId,
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'data' => [
                'total_orders' => (int) ($stats->total_orders ?? 0),
                'total_spent' => (float) ($stats->total_spent ?? 0),
                'total_cost' => (float) ($stats->total_cost ?? 0),
                'total_profit' => (float) ($stats->total_profit ?? 0),
                'total_refund' => (float) ($stats->total_refund ?? 0),
                'total_quantity' => (int) ($stats->total_quantity ?? 0),
                'status_counts' => [
                    'pending' => (int) ($stats->total_pending ?? 0),
                    'processing' => (int) ($stats->total_processing ?? 0),
                    'in_progress' => (int) ($stats->total_in_progress ?? 0),
                    'completed' => (int) ($stats->total_completed ?? 0),
                    'partial' => (int) ($stats->total_partial ?? 0),
                    'canceled' => (int) ($stats->total_canceled ?? 0),
                    'refunded' => (int) ($stats->total_refunded ?? 0),
                    'failed' => (int) ($stats->total_failed ?? 0),
                ],
            ],
        ]);
    }

    /**
     * Hủy đơn hàng
     */
    public function cancelOrder(Request $request, int $orderId): JsonResponse
    {
        $user = $request->user();

        // Lấy order với relationships
        $order = Order::with(['user', 'service.providerService.provider'])
            ->where('id', $orderId)
            ->first();

        if (!$order) {
            return response()->json([
                'message' => 'Đơn hàng không tồn tại.',
            ], 404);
        }

        // Kiểm tra quyền: user chỉ có thể hủy đơn của chính mình, admin có thể hủy mọi đơn
        if ($user->role !== 0 && $order->user_id !== $user->id) {
            return response()->json([
                'message' => 'Bạn không có quyền hủy đơn hàng này.',
            ], 403);
        }

        // Kiểm tra đơn hàng có thể hủy không
        if (!$order->canBeCanceled()) {
            return response()->json([
                'message' => 'Đơn hàng không thể hủy. Chỉ có thể hủy đơn hàng ở trạng thái: pending, processing, in_progress.',
                'current_status' => $order->status,
            ], 400);
        }

        // Khởi tạo activity logger
        $logger = OrderActivityLogger::for($order->id)->user($order->user_id);

        DB::beginTransaction();
        try {
            $provider = $order->service->providerService->provider ?? null;
            $providerOrderId = $order->provider_order_id;

            // Nếu đơn đã được đẩy lên provider, thử hủy ở provider trước
            if ($provider && $providerOrderId && ProviderFactory::isSupported($provider->code)) {
                try {
                    $logger->provider($provider->code, $providerOrderId);
                    $logger->providerRequest(
                        $provider->api_url . '/cancel',
                        ['order_id' => $providerOrderId]
                    );

                    $providerService = ProviderFactory::make($provider);
                    
                    // Gọi API cancel order từ provider (nếu có implement)
                    if (method_exists($providerService, 'canceledOrder')) {
                        $cancelResponse = $providerService->canceledOrder($providerOrderId);
                        $logger->providerResponse($cancelResponse);
                        
                        // Log kết quả
                        if (!$providerService->isSuccessResponse($cancelResponse)) {
                            $errorMsg = $cancelResponse['data']['error'] ?? $cancelResponse['body'] ?? 'Unknown error';
                            Log::warning('Provider cancel order failed', [
                                'order_id' => $order->id,
                                'provider_order_id' => $providerOrderId,
                                'error' => $errorMsg,
                            ]);
                            // Vẫn tiếp tục hủy ở hệ thống dù provider không hủy được
                        }
                    }
                } catch (\Exception $e) {
                    Log::error('Error canceling order at provider', [
                        'order_id' => $order->id,
                        'provider_order_id' => $providerOrderId,
                        'error' => $e->getMessage(),
                    ]);
                    // Vẫn tiếp tục hủy ở hệ thống
                }
            }

            // Tính toán số tiền hoàn lại
            // Nếu đơn đang pending (chưa đẩy lên provider) -> hoàn toàn bộ
            // Nếu đã đẩy lên provider -> có thể hoàn một phần hoặc toàn bộ tùy policy
            $refundAmount = $order->charge_amount;

            // Cập nhật order status
            $order->update([
                'status' => Order::STATUS_CANCELED,
                'refund_amount' => $refundAmount,
            ]);

            // Hoàn tiền cho user
            if ($refundAmount > 0) {
                $userOrder = $order->user;
                Dongtien::createTransaction(
                    $userOrder,
                    $refundAmount,
                    Dongtien::TYPE_REFUND,
                    "Hoàn tiền đơn hàng #{$order->id} đã hủy",
                    [
                        'order_id' => $order->id,
                        'payment_method' => 'system',
                    ]
                );

                $logger->refund($refundAmount);
            }

            // Log activity
            $logger->orderCanceled();

            DB::commit();

            // Load lại order với relationships
            $order->refresh();
            $order->load(['user', 'service', 'providerService.provider']);

            return response()->json([
                'message' => 'Hủy đơn hàng thành công.',
                'data' => [
                    'order' => $order,
                    'refund_amount' => (float) $refundAmount,
                    'new_balance' => $order->user ? (float) $order->user->balance : null,
                ],
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Error canceling order', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            $logger->error($e->getMessage(), $e);

            return response()->json([
                'message' => 'Lỗi khi hủy đơn hàng. Vui lòng thử lại.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
