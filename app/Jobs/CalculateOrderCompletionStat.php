<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\OrderCompletionStat;
use App\Models\Service;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CalculateOrderCompletionStat implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private Order $order) {}

    public function handle(): void
    {
        $order = $this->order;

        // Chỉ xử lý quantity từ 1 đến 1000
        if ($order->quantity < 1 || $order->quantity > 1000) {
            return;
        }

        // Cần có cả created_at và completed_at
        if (!$order->completed_at || !$order->created_at) {
            return;
        }

        $completionMinutes = (int) $order->created_at->diffInMinutes($order->completed_at);

        // Tránh lưu dữ liệu không hợp lệ (thời gian âm hoặc quá lớn - 30 ngày)
        if ($completionMinutes < 0 || $completionMinutes > 43200) {
            return;
        }

        OrderCompletionStat::updateOrCreate(
            ['order_id' => $order->id],
            [
                'service_id'         => $order->service_id,
                'quantity'           => $order->quantity,
                'completion_minutes' => $completionMinutes,
                'completed_at'       => $order->completed_at,
            ]
        );

        // Tính lại AVG 10 đơn gần nhất của dịch vụ này → cache vào bảng services
        $avg = OrderCompletionStat::where('service_id', $order->service_id)
            ->where('quantity', '>=', 1)
            ->where('quantity', '<=', 1000)
            ->orderBy('completed_at', 'desc')
            ->limit(10)
            ->avg('completion_minutes');

        Service::where('id', $order->service_id)
            ->update(['avg_completion_minutes' => $avg ? (int) round($avg) : null]);
    }
}
