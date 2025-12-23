<?php

namespace App\Helpers;

use App\Models\Order;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class OrderHelper
{
    public static function saveOrderToRedis(Order $order): bool
    {
        try {
            // Đảm bảo order đã load đầy đủ relationships
            if (!$order->relationLoaded('service')) {
                $order->load('service');
            }
            if (!$order->relationLoaded('providerService')) {
                $order->load('providerService.provider');
            }

            // Chuẩn bị dữ liệu đơn hàng để lưu vào Redis
            $orderData = [
                'id' => $order->id,
                'user_id' => $order->user_id,
                'service_id' => $order->service_id,
                'provider_service_id' => $order->provider_service_id,
                'link' => $order->link,
                'quantity' => $order->quantity,
                'status' => $order->status,
                'cost_rate' => $order->cost_rate,
                'sell_rate' => $order->sell_rate,
                'charge_amount' => $order->charge_amount,
                'cost_amount' => $order->cost_amount,
                'profit_amount' => $order->profit_amount,
                'created_at' => $order->created_at?->toDateTimeString(),
            ];

            // Lưu vào Redis dưới dạng JSON với connection REDIS_ORDER_WEB
            $jsonData = json_encode($orderData, JSON_UNESCAPED_UNICODE);

            Redis::connection(RedisHelper::REDIS_ORDER_WEB)->lpush(Order::KEY_ID_REDIS_ORDER, $jsonData);

            Log::info('OrderHelper: Order saved to Redis', [
                'order_id' => $order->id,
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::warning('OrderHelper: Failed to save order to Redis', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
