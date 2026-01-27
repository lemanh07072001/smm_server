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
        // Thống kê số lượng từng trạng thái
        // $statusCountsRaw = Order::selectRaw('status, COUNT(*) as count')
        //     ->groupBy('status')
        //     ->pluck('count', 'status')
        //     ->toArray();

        // $statusCounts = [
        //     'all' => array_sum($statusCountsRaw),
        //     'pending' => $statusCountsRaw['pending'] ?? 0,
        //     'processing' => $statusCountsRaw['processing'] ?? 0,
        //     'in_progress' => $statusCountsRaw['in_progress'] ?? 0,
        //     'completed' => $statusCountsRaw['completed'] ?? 0,
        //     'partial' => $statusCountsRaw['partial'] ?? 0,
        //     'canceled' => $statusCountsRaw['canceled'] ?? 0,
        //     'refunded' => $statusCountsRaw['refunded'] ?? 0,
        //     'failed' => $statusCountsRaw['failed'] ?? 0,
        // ];

        // Phân trang
        $perPage = $request->get('per_page', 10);
        $orders = $query->paginate($perPage);

        return response()->json([
            'data' => $orders->items(),
            'current_page' => $orders->currentPage(),
            'per_page' => $orders->perPage(),
            'total' => $orders->total(),
            'last_page' => $orders->lastPage(),
            // 'status_counts' => $statusCounts,
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
            $this->updateOrderAndRefund($order, $logger);

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
        // Admin (role = 0) có thể hủy mọi đơn, user chỉ hủy được đơn của mình
        return $user->role === 0 || $order->user_id === $user->id;
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
     * Cập nhật order status
     */
    private function updateOrderAndRefund(Order $order, OrderActivityLogger $logger): void
    {
        // Cập nhật order status về Processing khi hủy
        $order->update([
            'status' => Order::STATUS_PROCESSING,
        ]);

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
     * Lấy activity logs của order từ MongoDB
     */
    public function getOrderLogs(int $orderId): JsonResponse
    {
        $logs = OrderActivityLog::getByOrderId($orderId);

        return response()->json([
            'order_id' => $orderId,
            'total' => $logs->count(),
            'data' => $logs,
        ]);
    }
}
