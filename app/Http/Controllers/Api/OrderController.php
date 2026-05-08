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
use App\Services\OrderCreationService;
use App\Services\Providers\ProviderFactory;

class OrderController extends Controller
{
    public function __construct(private OrderCreationService $orderService) {}

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
                'created_at', 'updated_at', 'completed_at'
            ])
            ->with([
                'user:id,name,email',
                'service:id,name,sell_rate',
                'providerService:id,name,provider_service_code',
            ]);

        $userSearch = $request->input('user_search');

        // Search theo link, provider_order_id, user email/name
        if ($search) {
            $ids = collect(preg_split('/[\s,;]+/', trim($search)))
                ->map(fn($v) => trim($v))
                ->filter(fn($v) => is_numeric($v))
                ->map(fn($v) => (int)$v)
                ->values()
                ->all();

            if (count($ids) > 1) {
                $query->whereIn('id', $ids);
            } else {
                $query->where(function ($q) use ($search, $ids) {
                    $q->where('link', 'like', "%{$search}%")
                      ->orWhere('provider_order_id', 'like', "%{$search}%")
                      ->orWhere('id', !empty($ids) ? $ids[0] : -1);
                });
            }
        }

        // Filter theo user email hoặc tên
        if ($userSearch) {
            $query->whereHas('user', function ($q) use ($userSearch) {
                $q->where('email', 'like', "%{$userSearch}%")
                  ->orWhere('name', 'like', "%{$userSearch}%");
                if (is_numeric($userSearch)) {
                    $q->orWhere('id', (int) $userSearch);
                }
            });
        }

        // Filter theo status
        if ($status !== null && $status !== 'all') {
            $query->where('status', $status);
        }

        // Filter theo ngày
        if ($startDate) {
            $query->whereDate('created_at', '>=', $startDate);
        }
        if ($endDate) {
            $query->whereDate('created_at', '<=', $endDate);
        }

        // Thống kê số lượng từng trạng thái theo cùng filter (search/date), 1 query duy nhất
        $countQuery = Order::query();
        if ($search) {
            $ids = collect(preg_split('/[\s,;]+/', trim($search)))
                ->map(fn($v) => trim($v))
                ->filter(fn($v) => is_numeric($v))
                ->map(fn($v) => (int)$v)
                ->values()
                ->all();

            if (count($ids) > 1) {
                $countQuery->whereIn('id', $ids);
            } else {
                $countQuery->where(function ($q) use ($search, $ids) {
                    $q->where('link', 'like', "%{$search}%")
                      ->orWhere('provider_order_id', 'like', "%{$search}%")
                      ->orWhere('id', !empty($ids) ? $ids[0] : -1);
                });
            }
        }
        if ($userSearch) {
            $countQuery->whereHas('user', function ($q) use ($userSearch) {
                $q->where('email', 'like', "%{$userSearch}%")
                  ->orWhere('name', 'like', "%{$userSearch}%");
                if (is_numeric($userSearch)) {
                    $q->orWhere('id', (int) $userSearch);
                }
            });
        }
        if ($startDate) {
            $countQuery->whereDate('created_at', '>=', $startDate);
        }
        if ($endDate) {
            $countQuery->whereDate('created_at', '<=', $endDate);
        }

        $statusCountsRaw = (clone $countQuery)
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $allCount = array_sum($statusCountsRaw);

        $statusCounts = [
            'all'         => $allCount,
            'pending'     => $statusCountsRaw['pending'] ?? 0,
            'processing'  => $statusCountsRaw['processing'] ?? 0,
            'in_progress' => $statusCountsRaw['in_progress'] ?? 0,
            'completed'   => $statusCountsRaw['completed'] ?? 0,
            'partial'     => $statusCountsRaw['partial'] ?? 0,
            'canceled'    => $statusCountsRaw['canceled'] ?? 0,
            'refunded'    => $statusCountsRaw['refunded'] ?? 0,
            'failed'      => $statusCountsRaw['failed'] ?? 0,
        ];

        // Phân trang - orderBy id nhanh hơn created_at
        $perPage = min((int) $request->get('per_page', 10), 500);
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
        $platform = $request->input('platform');
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');
        $perPage = min((int) $request->input('per_page', 10), 500);

        // Query orders của user - chỉ select cột cần thiết
        $query = Order::select([
                'id', 'user_id', 'service_id', 'provider_service_id',
                'provider_order_id', 'link', 'quantity', 'status',
                'charge_amount', 'start_count', 'remains',
                'created_at', 'updated_at', 'completed_at'
            ])
            ->with(['service:id,name,sell_rate,group_id'])
            ->where('user_id', $userId);

        // Filter theo status (nếu status là "all" thì lấy tất cả)
        if ($status !== null && $status !== 'all') {
            $query->where('status', $status);
        }

        // Filter theo platform (group_id của category group, ví dụ: fb_comment, tiktok...)
        if ($platform) {
            $query->whereHas('service', fn($s) => $s->where('group_id', $platform));
        }

        // Filter theo ngày tạo
        if ($dateFrom) {
            $query->whereDate('created_at', '>=', $dateFrom);
        }
        if ($dateTo) {
            $query->whereDate('created_at', '<=', $dateTo);
        }

        // Tìm kiếm theo id, link, provider_order_id, tên dịch vụ
        if ($search) {
            $ids = collect(preg_split('/[\s,;]+/', trim($search)))
                ->map(fn($v) => trim($v))
                ->filter(fn($v) => is_numeric($v))
                ->map(fn($v) => (int)$v)
                ->values()
                ->all();

            if (count($ids) > 1) {
                $query->whereIn('id', $ids);
            } else {
                $query->where(function ($q) use ($search, $ids) {
                    $q->where('link', 'like', "%{$search}%")
                      ->orWhere('provider_order_id', 'like', "%{$search}%")
                      ->orWhereHas('service', fn($s) => $s->where('name', 'like', "%{$search}%"))
                      ->orWhere('id', !empty($ids) ? $ids[0] : -1);
                });
            }
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
        $validated = $request->validate([
            'service_id'          => ['required', 'integer', 'exists:services,id'],
            'provider_service_id' => ['required', 'integer', 'exists:provider_services,id'],
            'link'                => ['required', 'string', 'max:50000'],
            'quantity'            => ['required', 'integer', 'min:1', 'max:10000000'],
            'reactions'           => ['nullable', 'array'],
            'comments'            => ['nullable', 'string', 'max:60000'],
            'livestream_duration' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'time_vip'            => ['nullable', 'integer', 'min:1'],
            'number_per_date'     => ['nullable', 'integer', 'min:1'],
            'internal_note'       => ['nullable', 'string', 'max:1000'],
        ], [
            'comments.max' => 'Tổng nội dung comment quá dài (tối đa 60.000 ký tự).',
            'link.max' => 'Link quá dài.',
            'quantity.min' => 'Số lượng phải lớn hơn 0.',
            'quantity.max' => 'Số lượng vượt giới hạn.',
        ]);

        if (!empty($validated['comments'])) {
            $validated['comments'] = str_replace(["\r\n", "\r", "\n"], '\n', $validated['comments']);
        }

        $links = array_values(array_filter(
            array_map('trim', preg_split('/\\\\n|\r\n|\r|\n/', $validated['link'])),
            fn($l) => $l !== ''
        ));

        if (count($links) > 1) {
            return $this->addMultipleOrders($request, $validated, $links);
        }

        $validated['link'] = $links[0] ?? $validated['link'];

        $user = $request->user();

        $result = $this->orderService->validateService($validated['service_id'], $validated['provider_service_id']);
        if (isset($result['error'])) {
            return response()->json(['message' => $result['error']], $result['code']);
        }
        $service = $result['service'];

        $qtyError = $this->orderService->validateQuantity($service, $validated['quantity']);
        if ($qtyError) {
            return response()->json(['message' => $qtyError['error']], $qtyError['code']);
        }

        $livestreamDuration = $validated['livestream_duration'] ?? 0;
        if ($service->platform === 'fb_view_livestream' && empty($livestreamDuration)) {
            return response()->json(['message' => 'Vui lòng nhập thời gian livestream.'], 422);
        }

        $amounts = $this->orderService->calculateAmounts($service, $user, $validated['quantity'], $livestreamDuration);

        DB::beginTransaction();
        try {
            $user = \App\Models\User::where('id', $user->id)->lockForUpdate()->first();

            if ($user->balance < $amounts['chargeAmount']) {
                DB::rollBack();
                return response()->json([
                    'message'  => 'Số dư không đủ để thực hiện đơn hàng.',
                    'balance'  => (float) $user->balance,
                    'required' => (float) $amounts['chargeAmount'],
                    'shortage' => (float) ($amounts['chargeAmount'] - $user->balance),
                ], 400);
            }

            $order = $this->orderService->createOrderRecord(
                $user, $service, $validated['link'], $validated['quantity'],
                $amounts, 'web', $livestreamDuration,
                $validated['comments'] ?? null, $validated['internal_note'] ?? null,
                $validated['time_vip'] ?? null, $validated['number_per_date'] ?? null
            );

            DB::commit();

            $this->orderService->postCreateOrder($order, $user, $service, $amounts, $validated['quantity']);

            return response()->json([
                'message' => 'Tạo đơn hàng thành công.',
                'data'    => ['order' => $order, 'new_balance' => (float) $user->fresh()->balance],
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error creating order', [
                'error'      => $e->getMessage(),
                'user_id'    => $user->id,
                'service_id' => $validated['service_id'],
            ]);
            return response()->json([
                'message' => 'Lỗi khi tạo đơn hàng. Vui lòng thử lại.',
                'error'   => $e->getMessage(),
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
            // Reload với lock để tránh double refund do concurrent request
            $order = Order::where('id', $order->id)->lockForUpdate()->first();

            if ($order->status === Order::STATUS_CANCELED) {
                return;
            }

            $alreadyRefunded = (float) ($order->refund_amount ?? 0);
            $refundAmount    = max(0, (float) $order->charge_amount - $alreadyRefunded);

            $order->update(array_merge($canceledData, [
                'status'        => Order::STATUS_CANCELED,
                'refund_amount' => $alreadyRefunded + $refundAmount,
            ]));

            if ($refundAmount > 0) {
                $user = \App\Models\User::where('id', $order->user_id)->lockForUpdate()->first();
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
     * Tạo nhiều order từ danh sách link (phân tách bằng \n)
     */
    private function addMultipleOrders(Request $request, array $validated, array $links): JsonResponse
    {
        $user = $request->user();

        $result = $this->orderService->validateService($validated['service_id'], $validated['provider_service_id']);
        if (isset($result['error'])) {
            return response()->json(['message' => $result['error']], $result['code']);
        }
        $service = $result['service'];

        $quantity = $validated['quantity'];
        $qtyError = $this->orderService->validateQuantity($service, $quantity);
        if ($qtyError) {
            return response()->json(['message' => $qtyError['error']], $qtyError['code']);
        }

        $livestreamDuration = $validated['livestream_duration'] ?? 0;
        if ($service->platform === 'fb_view_livestream' && empty($livestreamDuration)) {
            return response()->json(['message' => 'Vui lòng nhập thời gian livestream.'], 422);
        }

        $amounts      = $this->orderService->calculateAmounts($service, $user, $quantity, $livestreamDuration);
        $totalCharge  = $amounts['chargeAmount'] * count($links);

        DB::beginTransaction();
        try {
            $user = \App\Models\User::where('id', $user->id)->lockForUpdate()->first();

            if ($user->balance < $totalCharge) {
                DB::rollBack();
                return response()->json([
                    'message'  => 'Số dư không đủ để thực hiện đơn hàng.',
                    'balance'  => (float) $user->balance,
                    'required' => (float) $totalCharge,
                    'shortage' => (float) ($totalCharge - $user->balance),
                ], 400);
            }

            $orders = [];
            foreach ($links as $link) {
                $orders[] = $this->orderService->createOrderRecord(
                    $user, $service, $link, $quantity,
                    $amounts, 'web', $livestreamDuration,
                    $validated['comments'] ?? null, $validated['internal_note'] ?? null,
                    $validated['time_vip'] ?? null, $validated['number_per_date'] ?? null
                );
            }

            DB::commit();

            foreach ($orders as $order) {
                $this->orderService->postCreateOrder($order, $user, $service, $amounts, $quantity);
            }

            return response()->json([
                'message' => 'Tạo ' . count($orders) . ' đơn hàng thành công.',
                'data'    => ['orders' => $orders, 'new_balance' => (float) $user->fresh()->balance],
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error creating multiple orders', [
                'error'      => $e->getMessage(),
                'user_id'    => $user->id,
                'service_id' => $validated['service_id'],
                'link_count' => count($links),
            ]);
            return response()->json([
                'message' => 'Lỗi khi tạo đơn hàng. Vui lòng thử lại.',
                'error'   => $e->getMessage(),
            ], 500);
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
