<?php

namespace App\Console\Commands;

use App\Models\Dongtien;
use App\Models\Order;
use App\Models\ReportDashboardDaily;
use App\Models\User;
use Illuminate\Console\Command;

class GenerateDashboardReport extends Command
{
    protected $signature = 'report:dashboard';

    protected $description = 'Tạo báo cáo thống kê dashboard theo ngày hôm nay';

    public function handle()
    {
        $date = date("Y-m-d");
        $dateAt = (int) date('Ymd');

        // Thống kê đơn hàng
        $orders = Order::where('created_at', '>=', "$date 00:00:00")
            ->where('created_at', '<=', "$date 23:59:59")
            ->cursor();

        $orderStats = [
            'total_orders' => 0,
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
        ];

        $customerIds = [];

        foreach ($orders as $order) {
            $orderStats['total_orders']++;

            // Đếm theo status
            $statusField = "order_{$order->status}";
            if (isset($orderStats[$statusField])) {
                $orderStats[$statusField]++;
            }

            // Lưu user_id để đếm số khách hàng
            $customerIds[$order->user_id] = true;

            // Cộng giá trị tài chính (không tính cho đơn refunded hoặc failed)
            if (!in_array($order->status, [Order::STATUS_REFUNDED, Order::STATUS_FAILED])) {
                $orderStats['total_charge'] += $order->charge_amount;
                $orderStats['total_cost'] += $order->cost_amount;
                $orderStats['total_profit'] += $order->profit_amount;
            } else {
                $orderStats['total_refund'] += $order->charge_amount;
            }
        }

        // Đếm số khách hàng có đơn
        $totalCustomers = count($customerIds);

        // Đếm khách hàng mới (đăng ký trong ngày)
        $newCustomers = User::where('created_at', '>=', "$date 00:00:00")
            ->where('created_at', '<=', "$date 23:59:59")
            ->count();

        // Thống kê giao dịch nạp tiền
        $deposits = Dongtien::where('type', Dongtien::TYPE_DEPOSIT)
            ->where('thoigian', '>=', "$date 00:00:00")
            ->where('thoigian', '<=', "$date 23:59:59")
            ->selectRaw('COUNT(*) as total_deposits, COALESCE(SUM(amount), 0) as deposit_amount')
            ->first();

        // Đếm khách hàng hoạt động (có giao dịch trong ngày)
        $activeCustomers = Dongtien::where('thoigian', '>=', "$date 00:00:00")
            ->where('thoigian', '<=', "$date 23:59:59")
            ->distinct('user_id')
            ->count('user_id');

        // Tổng doanh thu = tổng tiền nạp
        $totalRevenue = $deposits->deposit_amount ?? 0;

        ReportDashboardDaily::updateOrCreate(
            ['date_at' => $dateAt],
            [
                'total_orders' => $orderStats['total_orders'],
                'order_pending' => $orderStats['order_pending'],
                'order_processing' => $orderStats['order_processing'],
                'order_in_progress' => $orderStats['order_in_progress'],
                'order_completed' => $orderStats['order_completed'],
                'order_partial' => $orderStats['order_partial'],
                'order_canceled' => $orderStats['order_canceled'],
                'order_refunded' => $orderStats['order_refunded'],
                'order_failed' => $orderStats['order_failed'],
                'total_revenue' => $totalRevenue,
                'total_charge' => $orderStats['total_charge'],
                'total_cost' => $orderStats['total_cost'],
                'total_profit' => $orderStats['total_profit'],
                'total_refund' => $orderStats['total_refund'],
                'total_customers' => $totalCustomers,
                'new_customers' => $newCustomers,
                'active_customers' => $activeCustomers,
                'total_deposits' => $deposits->total_deposits ?? 0,
                'deposit_amount' => $deposits->deposit_amount ?? 0,
            ]
        );

        $this->info('Done');

        return 0;
    }
}
