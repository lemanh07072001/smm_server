<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\ReportOrderDaily;
use Illuminate\Console\Command;

class GenerateOrderReport extends Command
{
    protected $signature = 'report:order';

    protected $description = 'Quét các đơn scan=0 có status terminal, cập nhật ReportOrderDaily theo (user_id, service_id, date_at)';

    public function handle(): int
    {
        $orders = Order::whereIn('status', [
                Order::STATUS_COMPLETED,
                Order::STATUS_PARTIAL,
                Order::STATUS_CANCELED,
                Order::STATUS_FAILED,
            ])
            ->where('scan', 0)
            ->get(['id', 'user_id', 'service_id', 'status', 'created_at', 'charge_amount', 'cost_amount', 'profit_amount', 'quantity']);

        if ($orders->isEmpty()) {
            $this->info('Không có đơn mới cần xử lý');
            return 0;
        }

        $reports = [];

        foreach ($orders as $order) {
            $dateAt = strtotime(date('Y-m-d', strtotime($order->created_at)));
            $key    = "{$order->user_id}_{$order->service_id}_{$dateAt}";

            if (!isset($reports[$key])) {
                $reports[$key] = [
                    'user_id'           => $order->user_id,
                    'service_id'        => $order->service_id,
                    'date_at'           => $dateAt,
                    'order_pending'     => 0,
                    'order_processing'  => 0,
                    'order_in_progress' => 0,
                    'order_completed'   => 0,
                    'order_partial'     => 0,
                    'order_canceled'    => 0,
                    'order_refunded'    => 0,
                    'order_failed'      => 0,
                    'total_charge'      => 0,
                    'total_cost'        => 0,
                    'total_profit'      => 0,
                    'total_refund'      => 0,
                    'total_quantity'    => 0,
                ];
            }

            $statusField = "order_{$order->status}";
            if (isset($reports[$key][$statusField])) {
                $reports[$key][$statusField]++;
            }

            $reports[$key]['total_quantity'] += $order->quantity;

            if ($order->status === Order::STATUS_FAILED) {
                $reports[$key]['total_refund'] += $order->charge_amount;
            } else {
                $reports[$key]['total_charge']  += $order->charge_amount;
                $reports[$key]['total_cost']    += $order->cost_amount;
                $reports[$key]['total_profit']  += $order->profit_amount;
            }
        }

        foreach ($reports as $report) {
            $existing = ReportOrderDaily::where('user_id',    $report['user_id'])
                ->where('service_id', $report['service_id'])
                ->where('date_at',    $report['date_at'])
                ->first();

            if ($existing) {
                $existing->order_pending     += $report['order_pending'];
                $existing->order_processing  += $report['order_processing'];
                $existing->order_in_progress += $report['order_in_progress'];
                $existing->order_completed   += $report['order_completed'];
                $existing->order_partial     += $report['order_partial'];
                $existing->order_canceled    += $report['order_canceled'];
                $existing->order_refunded    += $report['order_refunded'];
                $existing->order_failed      += $report['order_failed'];
                $existing->total_charge      += $report['total_charge'];
                $existing->total_cost        += $report['total_cost'];
                $existing->total_profit      += $report['total_profit'];
                $existing->total_refund      += $report['total_refund'];
                $existing->total_quantity    += $report['total_quantity'];
                $existing->save();
            } else {
                ReportOrderDaily::create($report);
            }
        }

        $orderIds = $orders->pluck('id')->all();
        Order::whereIn('id', $orderIds)->update(['scan' => 1]);

        $this->info('Đã xử lý ' . count($orderIds) . ' đơn');

        return 0;
    }
}
