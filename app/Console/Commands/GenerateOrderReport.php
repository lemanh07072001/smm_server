<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\ReportOrderDaily;
use Illuminate\Console\Command;

class GenerateOrderReport extends Command
{
    protected $signature = 'report:order';

    protected $description = 'Tạo báo cáo thống kê đơn hàng theo ngày hôm nay từ các đơn hoàn thành chưa scan';

    public function handle()
    {
        $orders = Order::whereIn('status', [
                Order::STATUS_COMPLETED,
                Order::STATUS_PARTIAL,
                Order::STATUS_CANCELED,
                Order::STATUS_FAILED,
            ])
            ->where('scan', 0)
            ->cursor();

        $reports = [];
        $list_values = [
            'order_pending' => 0,
            'order_processing' => 0,
            'order_in_progress' => 0,
            'order_completed' => 0,
            'order_partial' => 0,
            'order_canceled' => 0,
            'order_refunded' => 0,
            'order_failed' => 0,
            'total_charge' => 0,
            'total_cost' => 0,
            'total_profit' => 0,
            'total_refund' => 0,
            'total_quantity' => 0,
        ];

        foreach ($orders as $order) {
            try {
                $dateAt = strtotime(date('Y-m-d', strtotime($order->created_at)));
                $keys = [
                    'date_at' => $dateAt,
                    'user_id' => $order->user_id,
                    'service_id' => $order->service_id,
                ];
                $reportKey = md5(implode('|', $keys));

                if (!isset($reports[$reportKey])) {
                    $reports[$reportKey] = array_merge($keys, $list_values);
                    $reports[$reportKey]['report_key'] = $reportKey;
                }

                // Đếm theo status
                $statusField = "order_{$order->status}";
                if (isset($reports[$reportKey][$statusField])) {
                    $reports[$reportKey][$statusField]++;
                }

                // Cộng số lượng cho tất cả đơn
                $reports[$reportKey]['total_quantity'] += $order->quantity;

                // Cộng giá trị tài chính (không tính cho đơn refunded hoặc failed)
                if (!in_array($order->status, [Order::STATUS_FAILED])) {
                    $reports[$reportKey]['total_charge'] += $order->charge_amount;
                    $reports[$reportKey]['total_cost'] += $order->cost_amount;
                    $reports[$reportKey]['total_profit'] += $order->profit_amount;
                } else {
                    // Chỉ cộng refund cho đơn refunded hoặc failed
                    $reports[$reportKey]['total_refund'] += $order->charge_amount;
                }

            } catch (\Throwable $th) {
                continue;
            }
        }

        foreach ($reports as $report) {
            try {
                ReportOrderDaily::updateOrCreate(
                    ['report_key' => $report['report_key']],
                    $report
                );
            } catch (\Throwable $th) {
                continue;
            }
        }

        // Cập nhật scan = 1 cho các đơn đã xử lý xong
        foreach ($orders as $order) {
            try {
                $order->update(['scan' => 1]);
            } catch (\Throwable $th) {
                continue;
            }
        }

        $this->info('Done');

        return 0;
    }
}
