<?php

namespace App\Http\Controllers\Api;

use App\Models\Order;
use App\Models\OrderActivityLog;
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
        $search = $request->input('search');
        $status = $request->input('status');
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');

        $query = Order::select([
                'id', 'user_id', 'service_id', 'provider_service_id',
                'provider_order_id', 'link', 'quantity', 'status',
                'charge_amount', 'cost_amount', 'profit_amount',
                'start_count', 'remains', 'error_message',
                'created_at', 'updated_at'
            ])
            ->with([
                'user:id,name,email',
                'service:id,name,sell_rate',
                'providerService:id,name,provider_service_code',
            ]);

        // Search theo link, provider_order_id
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('link', 'like', "%{$search}%")
                  ->orWhere('provider_order_id', 'like', "%{$search}%")
                  ->orWhere('id', $search);
            });
        }

        // Filter theo status
        if ($status !== null) {
            $query->where('status', $status);
        }

        // Filter theo ngày
        if ($startDate) {
            $query->whereDate('created_at', '>=', $startDate);
        }
        if ($endDate) {
            $query->whereDate('created_at', '<=', $endDate);
        }

        // Thống kê số lượng từng trạng thái (1 query nhẹ dùng index status)
        $statusCountsRaw = Order::selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $statusCounts = [
            'all' => Order::count(),
            'pending' => $statusCountsRaw['pending'] ?? 0,
            'processing' => $statusCountsRaw['processing'] ?? 0,
            'in_progress' => $statusCountsRaw['in_progress'] ?? 0,
            'completed' => $statusCountsRaw['completed'] ?? 0,
            'partial' => $statusCountsRaw['partial'] ?? 0,
            'canceled' => $statusCountsRaw['canceled'] ?? 0,
            'refunded' => $statusCountsRaw['refunded'] ?? 0,
            'failed' => $statusCountsRaw['failed'] ?? 0,
        ];

        // Phân trang - orderBy id nhanh hơn created_at
        $perPage = min((int) $request->get('per_page', 6), 100);
        $orders = $query->orderBy('id', 'desc')->paginate($perPage);

        return response()->json([
            'data' => $orders->items(),
            'current_page' => $orders->currentPage(),
            'per_page' => $orders->perPage(),
            'total' => $orders->total(),
            'last_page' => $orders->lastPage(),
            'status_counts' => $statusCounts,
        ]);
    }

    /**
     * Lấy tất cả đơn hàng theo user_id và thống kê số lượng từng trạng thái.
     */
    public function getOrdersByUser(Request $request, int $userId): JsonResponse
    {
        $authUser = $request->user();

        // User thường chỉ được xem đơn hàng của chính mình
        if (!$authUser->isAdmin() && $authUser->id !== $userId) {
            return $this->errorResponse('Bạn không có quyền xem đơn hàng của người dùng khác.', 403);
        }

        $search = $request->input('search');
        $status = $request->input('status');
        $perPage = min((int) $request->input('per_page', 7), 100);

        // Query orders của user - chỉ select cột cần thiết
        $query = Order::select([
                'id', 'user_id', 'service_id', 'provider_service_id',
                'provider_order_id', 'link', 'quantity', 'status',
                'charge_amount', 'start_count', 'remains',
                'created_at', 'updated_at'
            ])
            ->with(['service:id,name,sell_rate'])
            ->where('user_id', $userId);

        // Filter theo status (nếu status là "all" thì lấy tất cả)
        if ($status !== null && $status !== 'all') {
            $query->where('status', $status);
        }

        // Tìm kiếm theo link, provider_order_id
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('link', 'like', "%{$search}%")
                  ->orWhere('provider_order_id', 'like', "%{$search}%");
            });
        }

        // Thống kê số lượng từng trạng thái của user
        $statusCountsRaw = Order::where('user_id', $userId)
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $statusCounts = [
            'all' => array_sum($statusCountsRaw),
            'pending' => $statusCountsRaw['pending'] ?? 0,
            'processing' => $statusCountsRaw['processing'] ?? 0,
            'in_progress' => $statusCountsRaw['in_progress'] ?? 0,
            'completed' => $statusCountsRaw['completed'] ?? 0,
            'partial' => $statusCountsRaw['partial'] ?? 0,
            'canceled' => $statusCountsRaw['canceled'] ?? 0,
            'refunded' => $statusCountsRaw['refunded'] ?? 0,
            'failed' => $statusCountsRaw['failed'] ?? 0,
        ];

        // Dùng paginate thay vì count + skip/take
        $orders = $query->orderBy('id', 'desc')->paginate($perPage);

        return response()->json([
            'data' => $orders->items(),
            'total' => $orders->total(),
            'page' => $orders->currentPage(),
            'limit' => $orders->perPage(),
            'totalPages' => $orders->lastPage(),
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
            'quantity' => ['required', 'integer', 'min:1', 'max:10000000'],
            'reactions' => ['nullable', 'array'],
            'comments' => ['nullable', 'string', 'max:5000'],
            'livestream_duration' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'internal_note' => ['nullable', 'string', 'max:1000'],
        ]);

        // Convert newline thực thành literal \n để lưu DB
        if (!empty($validated['comments'])) {
            $validated['comments'] = str_replace(["\r\n", "\r", "\n"], '\n', $validated['comments']);
        }

        // Lấy user từ authenticated request
        $user = $request->user();

        // Lấy Service với ProviderService và Provider (nested relationship)
        $service = Service::with(['providerService.provider'])
            ->where('id', $validated['service_id'])
            ->where('provider_service_id', $validated['provider_service_id'])
            ->where('is_active', 1)
            ->first();

        if (!$service) {
            return response()->json([
                'message' => 'Service không tồn tại hoặc đã bị tắt.',
            ], 404);
        }

        $blockedStatuses = [
            Service::SERVICE_STATUS_MAINTENANCE => 'Dịch vụ đang bảo trì, vui lòng thử lại sau.',
            Service::SERVICE_STATUS_STOPPED     => 'Dịch vụ đang tạm dừng, vui lòng thử lại sau.',
            Service::SERVICE_STATUS_ERROR       => 'Dịch vụ đang lỗi, vui lòng thử lại sau.',
        ];

        if (isset($blockedStatuses[$service->service_status])) {
            return response()->json([
                'message' => $blockedStatuses[$service->service_status],
            ], 422);
        }

        // Validate quantity theo giới hạn của service
        $quantity = $validated['quantity'];
        if ($quantity < $service->min_quantity) {
            return response()->json([
                'message' => "Số lượng tối thiểu là {$service->min_quantity}.",
            ], 422);
        }
        if ($service->max_quantity && $quantity > $service->max_quantity) {
            return response()->json([
                'message' => "Số lượng tối đa là {$service->max_quantity}.",
            ], 422);
        }

        // Lấy provider thông qua providerService
        $provider = $service->providerService->provider;

        if (!$provider) {
            return response()->json([
                'message' => 'Provider không tồn tại.',
            ], 404);
        }

        if (!$provider->is_active) {
            return response()->json([
                'message' => 'Nhà cung cấp dịch vụ hiện không hoạt động.',
            ], 422);
        }

        // Kiểm tra provider có được hỗ trợ không
        if (!ProviderFactory::isSupported($provider->code)) {
            return response()->json([
                'message' => 'Provider không được hỗ trợ: ' . $provider->code,
            ], 400);
        }

        // Tính toán số tiền
        $costRate = $service->providerService->cost_rate;
        $sellRate = $service->getPriceForUser($user);
        $livestreamDuration = $validated['livestream_duration'] ?? 0;

        // Platform fb_view_livestream: tính theo công thức giá × thời gian × số mắt
        if ($service->platform === 'fb_view_livestream') {
            if (empty($livestreamDuration)) {
                return response()->json([
                    'message' => 'Vui lòng nhập thời gian livestream.',
                ], 422);
            }
            $costAmount = $costRate * $livestreamDuration * $quantity;
            $chargeAmount = $sellRate * $livestreamDuration * $quantity;
        } else {
            $costAmount = $costRate * $quantity;
            $chargeAmount = $sellRate * $quantity;
        }

        $profitAmount = $chargeAmount - $costAmount;

        DB::beginTransaction();
        try {
            // Lock user row để tránh race condition khi trừ tiền đồng thời
            $user = \App\Models\User::where('id', $user->id)->lockForUpdate()->first();

            // Kiểm tra số dư bên trong transaction sau khi đã lock
            if ($user->balance < $chargeAmount) {
                DB::rollBack();
                return response()->json([
                    'message' => 'Số dư không đủ để thực hiện đơn hàng.',
                    'balance' => (float) $user->balance,
                    'required' => (float) $chargeAmount,
                    'shortage' => (float) ($chargeAmount - $user->balance),
                ], 400);
            }

            // Tạo internal_note tự động cho reseller, hoặc dùng note do user truyền vào
            $internalNote = $validated['internal_note'] ?? null;
            if ($user->role === \App\Models\User::ROLE_RESELLER && empty($internalNote)) {
                $internalNote = "[Reseller] agent_price={$service->agent_price}, sell_rate={$sellRate}, charge={$chargeAmount}";
            }

            // Tạo order trong database với status pending
            $order = Order::create([
                'user_id' => $user->id,
                'order_source' => 'web',
                'service_id' => $validated['service_id'],
                'provider_service_id' => $validated['provider_service_id'],
                'link' => $validated['link'],
                'quantity' => $quantity,
                'livestream_duration' => $livestreamDuration ?: 0,
                'comments' => $validated['comments'] ?? null,
                'internal_note' => $internalNote,
                'status' => Order::STATUS_PENDING,
                'is_priority' => $service->priority ?? Order::PRIORITY[1],
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

            DB::commit();

            // Ghi log activity cho tất cả roles
            $roleLabel = match ($user->role) {
                \App\Models\User::ROLE_ADMIN       => 'admin',
                \App\Models\User::ROLE_SUPER_ADMIN => 'super_admin',
                \App\Models\User::ROLE_RESELLER    => 'reseller',
                default                            => 'user',
            };
            OrderActivityLogger::for($order->id)
                ->user($user->id)
                ->orderCreated([
                    'role'         => $roleLabel,
                    'service_id'   => $order->service_id,
                    'service_name' => $service->name,
                    'quantity'     => $quantity,
                    'sell_rate'    => $sellRate,
                    'charge_amount'=> $chargeAmount,
                    'link'         => $order->link,
                ]);

            // Push thẳng vào Redis queue, không chờ scan (giảm latency ~1 phút → ~100ms)
            $this->pushOrderToQueue($order);

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
        $authUser = $request->user();

        if (!$authUser->isAdmin() && $authUser->id !== $userId) {
            return $this->errorResponse('Bạn không có quyền xem thống kê của người dùng khác.', 403);
        }

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

        // Lấy order với relationships cần thiết
        $order = Order::with(['user', 'service.providerService.provider'])
            ->find($orderId);

        if (!$order) {
            return $this->errorResponse('Đơn hàng không tồn tại.', 404);
        }

        // Kiểm tra quyền
        if (!$this->canUserCancelOrder($user, $order)) {
            return $this->errorResponse('Bạn không có quyền hủy đơn hàng này.', 403);
        }

        // Kiểm tra status có thể hủy
        if (!$order->canBeCanceled()) {
            return $this->errorResponse(
                'Đơn hàng không thể hủy. Chỉ có thể hủy đơn hàng ở trạng thái: pending, processing, in_progress.',
                400
            );
        }

        $logger = OrderActivityLogger::for($order->id)->user($order->user_id);

        DB::beginTransaction();
        try {
            // Hủy ở provider nếu có
            $this->cancelOrderAtProvider($order, $logger);

            // Cập nhật order và hoàn tiền
            $this->updateOrderAndRefund($order, $logger, $user->id);

            DB::commit();

            return response()->json([
                'message' => 'Hủy đơn hàng thành công.',
                'status' => 'SUCCESS',
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Error canceling order', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $logger->error($e->getMessage(), $e);

            return $this->errorResponse('Lỗi khi hủy đơn hàng. Vui lòng thử lại.', 500);
        }
    }

    /**
     * Kiểm tra user có quyền hủy đơn hàng không
     */
    private function canUserCancelOrder($user, Order $order): bool
    {
        // Admin/Super-admin có thể hủy mọi đơn, user chỉ hủy được đơn của mình
        return $user->isAdmin() || $order->user_id === $user->id;
    }

    /**
     * Hủy đơn hàng ở provider
     */
    private function cancelOrderAtProvider(Order $order, OrderActivityLogger $logger): void
    {
        $provider = $order->service->providerService->provider ?? null;
        $providerOrderId = $order->provider_order_id;

        if (!$provider || !$providerOrderId || !ProviderFactory::isSupported($provider->code)) {
            return;
        }

        try {
            $logger->provider($provider->code, $providerOrderId);
            $logger->providerRequest(
                $provider->api_url . '/cancel',
                ['order_id' => $providerOrderId]
            );

            $providerService = ProviderFactory::make($provider);
            
            if (!method_exists($providerService, 'canceledOrder')) {
                return;
            }

            $cancelResponse = $providerService->canceledOrder($providerOrderId);
            $logger->providerResponse($cancelResponse);
            
            if (!$providerService->isSuccessResponse($cancelResponse)) {
                $errorMsg = $cancelResponse['data']['error'] ?? $cancelResponse['body'] ?? 'Unknown error';
                Log::warning('Provider cancel order failed', [
                    'order_id' => $order->id,
                    'provider_order_id' => $providerOrderId,
                    'error' => $errorMsg,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Error canceling order at provider', [
                'order_id' => $order->id,
                'provider_order_id' => $providerOrderId,
                'error' => $e->getMessage(),
            ]);
            // Vẫn tiếp tục hủy ở hệ thống dù provider lỗi
        }
    }

    /**
     * Cập nhật order status và hoàn tiền cho user
     */
    private function updateOrderAndRefund(Order $order, OrderActivityLogger $logger, int $canceledBy): void
    {
        $canceledData = [
            'canceled_at' => now(),
            'canceled_by' => $canceledBy,
        ];

        // Chưa gửi lên provider → hoàn tiền ngay, set CANCELED luôn
        if (!$order->provider_order_id) {
            $refundAmount = (float) $order->charge_amount;

            $order->update(array_merge($canceledData, [
                'status'        => Order::STATUS_CANCELED,
                'refund_amount' => $refundAmount,
            ]));

            if ($refundAmount > 0) {
                $user = \App\Models\User::lockForUpdate()->find($order->user_id);
                if ($user) {
                    Dongtien::createTransaction(
                        $user,
                        $refundAmount,
                        Dongtien::TYPE_REFUND,
                        "Hoàn tiền đơn hàng #{$order->id} đã hủy",
                        ['order_id' => $order->id]
                    );
                }
            }
        } else {
            // Đã gửi lên provider → chờ provider xử lý hoàn (STATUS_PROCESSING)
            $order->update(array_merge($canceledData, [
                'status' => Order::STATUS_PROCESSING,
            ]));
        }

        $logger->orderCanceled();
    }

    /**
     * Trả về response lỗi chuẩn
     */
    private function errorResponse(string $message, int $statusCode = 400): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'status' => 'FAILED',
        ], $statusCode);
    }

    /**
     * Push order vào Redis queue ngay sau khi tạo.
     * scan() chỉ còn là fallback recovery cho order bị sót.
     */
    private function pushOrderToQueue(Order $order): void
    {
        try {
            $orderData = json_encode(['id' => $order->id]);
            if ($order->is_priority == Order::PRIORITY[0]) {
                \App\Helpers\RedisHelper::lpush(Order::KEY_ID_REDIS_ORDER_PRIORITY_0, $orderData);
            } else {
                \App\Helpers\RedisHelper::rpush(Order::KEY_ID_REDIS_ORDER_PRIORITY_0, $orderData);
            }
            OrderActivityLogger::for($order->id)->user($order->user_id)->orderQueued();
        } catch (\Exception $e) {
            // Silent fail - scan() sẽ recovery sau
            Log::warning('OrderController: failed to push order to queue', [
                'order_id' => $order->id,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    /**
     * Lấy activity logs của order từ MongoDB
     */
    public function getOrderLogs(Request $request, int $orderId): JsonResponse
    {
        $user = $request->user();

        $order = Order::find($orderId);
        if (!$order) {
            return $this->errorResponse('Đơn hàng không tồn tại.', 404);
        }

        if (!$user->isAdmin() && $order->user_id !== $user->id) {
            return $this->errorResponse('Bạn không có quyền xem log đơn hàng này.', 403);
        }

        $logs = OrderActivityLog::getByOrderId($orderId);

        return response()->json([
            'order_id' => $orderId,
            'total' => $logs->count(),
            'data' => $logs,
        ]);
    }
}
