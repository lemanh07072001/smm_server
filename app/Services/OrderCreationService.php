<?php

namespace App\Services;

use App\Helpers\OrderActivityLogger;
use App\Helpers\RedisHelper;
use App\Models\Dongtien;
use App\Models\Order;
use App\Models\Service;
use App\Models\User;
use App\Services\Providers\ProviderFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrderCreationService
{
    /**
     * Validate service và trả về service object hoặc array lỗi.
     * Dùng chung cho web và API.
     */
    public function validateService(int $serviceId, ?int $providerServiceId = null): array
    {
        $query = Service::with(['providerService.provider'])
            ->where('id', $serviceId)
            ->where('is_active', 1);

        if ($providerServiceId !== null) {
            $query->where('provider_service_id', $providerServiceId);
        }

        $service = $query->first();

        if (!$service) {
            return ['error' => 'Service không tồn tại hoặc đã bị tắt.', 'code' => 404];
        }

        $blockedStatuses = [
            Service::SERVICE_STATUS_MAINTENANCE => 'Dịch vụ đang bảo trì, vui lòng thử lại sau.',
            Service::SERVICE_STATUS_STOPPED     => 'Dịch vụ đang tạm dừng, vui lòng thử lại sau.',
            Service::SERVICE_STATUS_ERROR       => 'Dịch vụ đang lỗi, vui lòng thử lại sau.',
        ];

        if (isset($blockedStatuses[$service->service_status])) {
            return ['error' => $blockedStatuses[$service->service_status], 'code' => 422];
        }

        $provider = $service->providerService->provider;

        if (!$provider) {
            return ['error' => 'Provider không tồn tại.', 'code' => 404];
        }

        if (!$provider->is_active) {
            return ['error' => 'Nhà cung cấp dịch vụ hiện không hoạt động.', 'code' => 422];
        }

        if (!ProviderFactory::isSupported($provider->code)) {
            return ['error' => 'Provider không được hỗ trợ: ' . $provider->code, 'code' => 400];
        }

        return ['service' => $service];
    }

    /**
     * Validate quantity theo giới hạn của service.
     */
    public function validateQuantity(Service $service, int $quantity): ?array
    {
        if ($quantity < $service->min_quantity) {
            return ['error' => "Số lượng tối thiểu là {$service->min_quantity}.", 'code' => 422];
        }

        if ($service->max_quantity && $quantity > $service->max_quantity) {
            return ['error' => "Số lượng tối đa là {$service->max_quantity}.", 'code' => 422];
        }

        return null;
    }

    /**
     * Tính toán charge/cost amount.
     */
    public function calculateAmounts(Service $service, User $user, int $quantity, int $livestreamDuration = 0): array
    {
        $costRate = $service->providerService->cost_rate;
        $sellRate = $service->getPriceForUser($user);

        if ($service->platform === 'fb_view_livestream') {
            $costAmount   = $costRate * $livestreamDuration * $quantity;
            $chargeAmount = $sellRate * $livestreamDuration * $quantity;
        } else {
            $costAmount   = $costRate * $quantity;
            $chargeAmount = $sellRate * $quantity;
        }

        return [
            'costRate'     => $costRate,
            'sellRate'     => $sellRate,
            'costAmount'   => $costAmount,
            'chargeAmount' => $chargeAmount,
            'profitAmount' => $chargeAmount - $costAmount,
        ];
    }

    /**
     * Tạo một order trong DB (phải gọi trong transaction đã mở).
     */
    public function createOrderRecord(
        User $user,
        Service $service,
        string $link,
        int $quantity,
        array $amounts,
        string $source = 'web',
        int $livestreamDuration = 0,
        ?string $comments = null,
        ?string $internalNote = null
    ): Order {
        if ($user->role === User::ROLE_RESELLER && empty($internalNote)) {
            $internalNote = "[Reseller] agent_price={$service->agent_price}, sell_rate={$amounts['sellRate']}, charge={$amounts['chargeAmount']}";
        }

        $order = Order::create([
            'user_id'             => $user->id,
            'order_source'        => $source,
            'service_id'          => $service->id,
            'provider_service_id' => $service->provider_service_id,
            'link'                => $link,
            'quantity'            => $quantity,
            'livestream_duration' => $livestreamDuration ?: 0,
            'comments'            => $comments,
            'internal_note'       => $internalNote,
            'status'              => Order::STATUS_PENDING,
            'is_priority'         => $service->priority ?? Order::PRIORITY[1],
            'cost_rate'           => $amounts['costRate'],
            'sell_rate'           => $amounts['sellRate'],
            'charge_amount'       => $amounts['chargeAmount'],
            'cost_amount'         => $amounts['costAmount'],
            'profit_amount'       => $amounts['profitAmount'],
            'refund_amount'       => 0,
            'final_charge'        => 0,
            'final_cost'          => 0,
            'final_profit'        => 0,
            'is_finalized'        => false,
        ]);

        Dongtien::createTransaction(
            $user,
            $amounts['chargeAmount'],
            Dongtien::TYPE_CHARGE,
            "Mua dịch vụ #{$service->id} - {$service->name}",
            ['order_id' => $order->id]
        );

        return $order;
    }

    /**
     * Ghi activity log và push queue sau khi commit.
     */
    public function postCreateOrder(Order $order, User $user, Service $service, array $amounts, int $quantity): void
    {
        $order->load(['user', 'service', 'providerService.provider']);

        $roleLabel = match ($user->role) {
            User::ROLE_ADMIN       => 'admin',
            User::ROLE_SUPER_ADMIN => 'super_admin',
            User::ROLE_RESELLER    => 'reseller',
            default                => 'user',
        };

        OrderActivityLogger::for($order->id)
            ->user($user->id)
            ->orderCreated([
                'role'          => $roleLabel,
                'service_id'    => $order->service_id,
                'service_name'  => $service->name,
                'quantity'      => $quantity,
                'sell_rate'     => $amounts['sellRate'],
                'charge_amount' => $amounts['chargeAmount'],
                'link'          => $order->link,
            ]);

        $this->pushOrderToQueue($order);
    }

    /**
     * Push order vào Redis queue.
     */
    public function pushOrderToQueue(Order $order): void
    {
        try {
            $orderData = json_encode(['id' => $order->id]);
            if ($order->is_priority == Order::PRIORITY[0]) {
                RedisHelper::lpush(Order::KEY_ID_REDIS_ORDER_PRIORITY_0, $orderData);
            } else {
                RedisHelper::rpush(Order::KEY_ID_REDIS_ORDER_PRIORITY_0, $orderData);
            }
            OrderActivityLogger::for($order->id)->user($order->user_id)->orderQueued();
        } catch (\Exception $e) {
            Log::warning('OrderCreationService: failed to push order to queue', [
                'order_id' => $order->id,
                'error'    => $e->getMessage(),
            ]);
        }
    }
}
