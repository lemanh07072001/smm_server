<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AffiliateCommission;
use App\Models\Dongtien;
use App\Models\Order;
use App\Models\ProviderService;
use App\Models\Service;
use App\Models\User;
use App\Services\OrderCreationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ApiOrderController extends Controller
{
    public function __construct(private OrderCreationService $orderService) {}

    /**
     * Dispatcher chính - phân loại action
     */
    public function handle(Request $request): JsonResponse
    {
        $action = $request->input('action');

        return match ($action) {
            'services'      => $this->services($request),
            'add'           => $this->addOrder($request),
            'status'        => $this->orderStatus($request),
            'refill'        => $this->createRefill($request),
            'refill_status' => $this->refillStatus($request),
            'cancel'        => $this->cancelOrders($request),
            'balance'       => $this->balance($request),
            default         => response()->json(['error' => 'Invalid action'], 400),
        };
    }

    /**
     * Danh sách dịch vụ cho phép mua qua API
     */
    private function services(Request $request): JsonResponse
    {
        $services = Service::where('is_active', true)
            ->where('api_accessible', true)
            ->with('categoryGroup:id,name')
            ->get();

        $statusLabels = Service::SERVICE_STATUSES;

        $result = $services->map(fn(Service $s) => [
            'service'        => $s->id,
            'name'           => $s->name,
            'type'           => $s->group_id ?? 'Default',
            'category'       => $s->categoryGroup?->name ?? '',
            'rate'           => (string) $s->sell_rate,
            'min'            => (string) $s->min_quantity,
            'max'            => (string) $s->max_quantity,
            'refill'         => false,
            'status'         => $s->service_status,
            'status_label'   => $statusLabels[$s->service_status] ?? 'Không xác định',
            'available'      => $s->service_status === Service::SERVICE_STATUS_ACTIVE,
        ]);

        return response()->json($result);
    }

    /**
     * Tạo order mới qua API
     */
    private function addOrder(Request $request): JsonResponse
    {
        $serviceId = $request->input('service');
        $link      = $request->input('link');
        $quantity  = (int) $request->input('quantity');

        if (!$serviceId || !$link || !$quantity) {
            return response()->json(['error' => 'Missing required fields: service, link, quantity'], 400);
        }

        $links = array_values(array_filter(
            array_map('trim', preg_split('/\\\\n|\r\n|\r|\n/', $link)),
            fn($l) => $l !== ''
        ));

        $linksToCreate = count($links) > 1 ? $links : [$links[0] ?? $link];

        $serviceResult = $this->validateApiService($serviceId);
        if (isset($serviceResult['error'])) {
            return response()->json($serviceResult['error'], $serviceResult['code']);
        }
        $service = $serviceResult['service'];

        $qtyError = $this->orderService->validateQuantity($service, $quantity);
        if ($qtyError) {
            return response()->json(['error' => $qtyError['error']], $qtyError['code']);
        }

        $user    = $request->user();
        $amounts = $this->orderService->calculateAmounts($service, $user, $quantity);
        $total   = $amounts['chargeAmount'] * count($linksToCreate);

        DB::beginTransaction();
        try {
            $user = User::where('id', $user->id)->lockForUpdate()->first();

            if ($user->balance < $total) {
                DB::rollBack();
                return response()->json(['error' => 'Insufficient balance'], 400);
            }

            $orders   = [];
            $orderIds = [];
            foreach ($linksToCreate as $singleLink) {
                $order = $this->orderService->createOrderRecord(
                    $user, $service, $singleLink, $quantity,
                    $amounts, 'api', 0, $request->input('comments')
                );
                $orders[]   = $order;
                $orderIds[] = $order->id;
            }

            DB::commit();

            foreach ($orders as $order) {
                $this->orderService->postCreateOrder($order, $user, $service, $amounts, $quantity);
            }

            return count($orderIds) === 1
                ? response()->json(['order' => $orderIds[0]])
                : response()->json(['orders' => $orderIds]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('API addOrder error', ['error' => $e->getMessage(), 'user_id' => $user->id]);
            return response()->json(['error' => 'Failed to create order'], 500);
        }
    }

    /**
     * Validate service cho API (thêm check api_accessible)
     */
    private function validateApiService(int $serviceId): array
    {
        $service = Service::with(['providerService.provider'])
            ->where('id', $serviceId)
            ->where('is_active', 1)
            ->first();

        if (!$service) {
            return ['error' => ['error' => 'Service not found'], 'code' => 404];
        }

        if (!$service->api_accessible) {
            return ['error' => ['error' => 'This service is not available via API'], 'code' => 403];
        }

        $blockedStatuses = [
            Service::SERVICE_STATUS_MAINTENANCE => 'Service is under maintenance, please try again later',
            Service::SERVICE_STATUS_SLOW        => 'Service is running slow, orders may be delayed',
            Service::SERVICE_STATUS_STOPPED     => 'Service is temporarily stopped',
            Service::SERVICE_STATUS_ERROR       => 'Service is currently having errors, please try again later',
        ];
        if (isset($blockedStatuses[$service->service_status])) {
            return ['error' => ['error' => $blockedStatuses[$service->service_status]], 'code' => 422];
        }

        $provider = $service->providerService->provider ?? null;
        if (!$provider || !$provider->is_active) {
            return ['error' => ['error' => 'Provider is unavailable'], 'code' => 422];
        }

        return ['service' => $service];
    }

    /**
     * Kiểm tra trạng thái đơn hàng (đơn lẻ hoặc nhiều đơn)
     */
    private function orderStatus(Request $request): JsonResponse
    {
        $user = $request->user();

        // Nhiều order
        if ($request->has('orders')) {
            $ids = array_map('intval', explode(',', $request->input('orders')));
            $ids = array_filter($ids);

            $orders = Order::whereIn('id', $ids)
                ->where('user_id', $user->id)
                ->get()
                ->keyBy('id');

            $result = [];
            foreach ($ids as $id) {
                if (!isset($orders[$id])) {
                    $result[(string) $id] = ['error' => 'Incorrect order ID'];
                    continue;
                }
                $result[(string) $id] = $this->formatOrderStatus($orders[$id]);
            }

            return response()->json($result);
        }

        // Đơn lẻ
        $orderId = (int) $request->input('order');
        $order   = Order::where('id', $orderId)->where('user_id', $user->id)->first();

        if (!$order) {
            return response()->json(['error' => 'Incorrect order ID'], 404);
        }

        return response()->json($this->formatOrderStatus($order));
    }

    /**
     * Format trạng thái order theo chuẩn API
     */
    private function formatOrderStatus(Order $order): array
    {
        return [
            'charge'      => number_format((float) $order->charge_amount, 5, '.', ''),
            'start_count' => (string) ($order->start_count ?? 0),
            'status'      => $this->mapStatusToApiText($order->status),
            'remains'     => (string) ($order->remains ?? 0),
            'currency'    => 'VND',
        ];
    }

    /**
     * Map trạng thái nội bộ sang text chuẩn API
     */
    private function mapStatusToApiText(string $status): string
    {
        return match ($status) {
            Order::STATUS_PENDING     => 'Pending',
            Order::STATUS_IN_PROGRESS => 'In progress',
            Order::STATUS_COMPLETED   => 'Completed',
            Order::STATUS_PROCESSING  => 'Processing',
            Order::STATUS_PARTIAL     => 'Partial',
            Order::STATUS_CANCELED    => 'Canceled',
            Order::STATUS_FAILED      => 'Canceled',
            default                   => 'Pending',
        };
    }

    /**
     * Tạo refill (đơn lẻ hoặc nhiều)
     */
    private function createRefill(Request $request): JsonResponse
    {
        $user = $request->user();

        // Nhiều order
        if ($request->has('orders')) {
            $ids    = array_map('intval', explode(',', $request->input('orders')));
            $ids    = array_unique(array_filter($ids));
            $orders = Order::whereIn('id', $ids)->where('user_id', $user->id)->get()->keyBy('id');

            $result = [];
            foreach ($ids as $id) {
                if (!isset($orders[$id])) {
                    $result[] = ['order' => $id, 'refill' => ['error' => 'Incorrect order ID']];
                    continue;
                }

                $refillId = $this->doRefill($orders[$id]);
                $result[] = ['order' => $id, 'refill' => $refillId];
            }

            return response()->json($result);
        }

        // Đơn lẻ
        $orderId = (int) $request->input('order');
        $order   = Order::where('id', $orderId)->where('user_id', $user->id)->first();

        if (!$order) {
            return response()->json(['error' => 'Incorrect order ID'], 404);
        }

        $refillId = $this->doRefill($order);

        return response()->json(['refill' => (string) $refillId]);
    }

    /**
     * Thực hiện refill cho 1 order, trả về refill_id (dùng order_id làm refill_id)
     */
    private function doRefill(Order $order): int
    {
        if (in_array($order->status, [Order::STATUS_COMPLETED, Order::STATUS_PARTIAL])) {
            $order->update(['status' => Order::STATUS_REFILLING]);
        }
        return $order->id;
    }

    /**
     * Kiểm tra trạng thái refill (đơn lẻ hoặc nhiều)
     */
    private function refillStatus(Request $request): JsonResponse
    {
        $user = $request->user();

        // Nhiều refill
        if ($request->has('refills')) {
            $ids    = array_map('intval', explode(',', $request->input('refills')));
            $ids    = array_unique(array_filter($ids));
            $orders = Order::whereIn('id', $ids)->where('user_id', $user->id)->get()->keyBy('id');

            $result = [];
            foreach ($ids as $id) {
                $status = isset($orders[$id])
                    ? $this->mapStatusToApiText($orders[$id]->status)
                    : 'Not found';

                $result[] = ['refill' => (string) $id, 'status' => $status];
            }

            return response()->json($result);
        }

        // Đơn lẻ
        $refillId = (int) $request->input('refill');
        $order    = Order::where('id', $refillId)->where('user_id', $user->id)->first();

        if (!$order) {
            return response()->json(['error' => 'Incorrect refill ID'], 404);
        }

        return response()->json(['status' => $this->mapStatusToApiText($order->status)]);
    }

    /**
     * Hủy nhiều đơn hàng
     */
    private function cancelOrders(Request $request): JsonResponse
    {
        $user = $request->user();

        $ids    = array_map('intval', explode(',', $request->input('orders', '')));
        $ids    = array_unique(array_filter($ids));

        if (empty($ids)) {
            return response()->json(['error' => 'Missing orders parameter'], 400);
        }

        $orders = Order::whereIn('id', $ids)->where('user_id', $user->id)->get()->keyBy('id');

        $result = [];
        foreach ($ids as $id) {
            if (!isset($orders[$id])) {
                $result[] = ['order' => $id, 'cancel' => ['error' => 'Incorrect order ID']];
                continue;
            }

            $order = $orders[$id];

            if (!$order->canBeCanceled()) {
                $result[] = ['order' => $id, 'cancel' => ['error' => 'Order cannot be canceled']];
                continue;
            }

            try {
                DB::beginTransaction();

                $order->update(['status' => Order::STATUS_PROCESSING]);

                DB::commit();

                $result[] = ['order' => $id, 'cancel' => 1];
            } catch (\Exception $e) {
                DB::rollBack();
                $result[] = ['order' => $id, 'cancel' => ['error' => 'Failed to cancel']];
            }
        }

        return response()->json($result);
    }

    /**
     * Số dư tài khoản
     */
    private function balance(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'balance'  => number_format((float) $user->balance, 5, '.', ''),
            'currency' => 'VND',
        ]);
    }
}
