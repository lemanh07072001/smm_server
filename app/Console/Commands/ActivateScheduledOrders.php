<?php

namespace App\Console\Commands;

use App\Helpers\OrderActivityLogger;
use App\Helpers\RedisHelper;
use App\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class ActivateScheduledOrders extends Command
{
    protected $signature = 'order:activate-scheduled';

    protected $description = 'Kích hoạt các đơn hẹn giờ đã đến giờ chạy (chuyển scheduled → pending và đẩy vào queue)';

    public function handle(): int
    {
        $now = now();

        $orders = Order::where('status', Order::STATUS_SCHEDULED)
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', $now)
            ->limit(500)
            ->get();

        if ($orders->isEmpty()) {
            return self::SUCCESS;
        }

        foreach ($orders as $order) {
            $order->update(['status' => Order::STATUS_PENDING]);

            try {
                $orderData = json_encode(['id' => $order->id]);

                if ($order->is_priority == Order::PRIORITY[0]) {
                    RedisHelper::lpush(Order::KEY_ID_REDIS_ORDER_PRIORITY_0, $orderData);
                } else {
                    RedisHelper::rpush(Order::KEY_ID_REDIS_ORDER_PRIORITY_0, $orderData);
                }

                Redis::pipeline(function ($pipe) use ($order) {
                    $pipe->setex('scan_queued:order:' . $order->id, 120, 1);
                });

                OrderActivityLogger::for($order->id)->user($order->user_id)->orderQueued();
            } catch (\Exception $e) {
                Log::warning('ActivateScheduledOrders: failed to push order to queue', [
                    'order_id' => $order->id,
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        $this->info('[' . $now->format('H:i:s') . '] Đã kích hoạt ' . $orders->count() . ' đơn hẹn giờ.');

        return self::SUCCESS;
    }
}
