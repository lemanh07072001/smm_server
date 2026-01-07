<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\ReportOrderDaily;
use Illuminate\Console\Command;

class GenerateOrderReport extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'report:order {date}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Tạo báo cáo thống kê đơn hàng theo ngày truyền vào';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info(date("Y-m-d H:i:s") . ' Start');
        $date = date("Y-m-d", strtotime($this->argument('date')));
        $this->genReport($date);
        $this->info("\n" . date("Y-m-d H:i:s") . ' Done');
        return 0;
    }

    public function genReport($date)
    {
        echo "\nStarting query: " . date('H:i:s d-m-Y') . " for report date: " . $date;

        $orders = Order::where('created_at', '>=', "$date 00:00:00")
            ->where('created_at', '<=', "$date 23:59:59")
            ->cursor();

        echo "\nEnding query: " . date('H:i:s d-m-Y');

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

        echo "\nStarting foreach: " . date('H:i:s d-m-Y');

        foreach ($orders as $key => $order) {
            try {
                if ($key % 5000 == 0 && $key > 0) {
                    echo "\n  → Đã xử lý " . $key . " orders...";
                }

                $dateAt = (int) date('Ymd', strtotime($order->created_at));
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

                // Cộng giá trị tài chính
                $reports[$reportKey]['total_quantity'] += $order->quantity;
                $reports[$reportKey]['total_charge'] += $order->charge_amount;
                $reports[$reportKey]['total_cost'] += $order->cost_amount;
                $reports[$reportKey]['total_profit'] += $order->profit_amount;
                $reports[$reportKey]['total_refund'] += $order->refund_amount;

            } catch (\Throwable $th) {
                echo "\nError: " . $th->getMessage();
                continue;
            }
        }

        echo "\nEnding foreach: " . date('H:i:s d-m-Y');
        echo "\nTổng số reports: " . count($reports);

        echo "\nStarting save to DB: " . date('H:i:s d-m-Y');

        foreach ($reports as $report) {
            try {
                echo " .";
                ReportOrderDaily::updateOrCreate(
                    ['report_key' => $report['report_key']],
                    $report
                );
            } catch (\Throwable $th) {
                echo "\nError: " . $th->getMessage();
                continue;
            }
        }

        echo "\nEnding save to DB: " . date('H:i:s d-m-Y');
    }
}
