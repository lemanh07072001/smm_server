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
    protected $signature = 'report:order';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Tạo báo cáo thống kê đơn hàng theo ngày (incremental)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $now = now();

        $this->info("🚀 Bắt đầu thống kê: " . $now->format('H:i:s d-m-Y'));

        // Query orders mới hoặc đã thay đổi
        $this->line("📊 Đang query orders...");

        $orders = Order::where(function ($q) {
                $q->whereNull('scanned_at')
                  ->orWhereColumn('updated_at', '>', 'scanned_at');
            })
            ->cursor();

        // Khởi tạo mảng để gom nhóm theo report_key
        $reports = [];
        $orderIds = [];
        $count = 0;

        $this->line("🔄 Đang xử lý dữ liệu...");

        foreach ($orders as $order) {
            try {
                if ($count % 5000 == 0 && $count > 0) {
                    $this->line("  → Đã xử lý {$count} orders...");
                }

                $dateAt = (int) date('Ymd', strtotime($order->created_at));
                $reportKey = md5("{$dateAt}|{$order->user_id}|{$order->service_id}");

                // Khởi tạo report nếu chưa có
                if (!isset($reports[$reportKey])) {
                    // Lấy report hiện tại từ DB (nếu có)
                    $existingReport = ReportOrderDaily::where('report_key', $reportKey)->first();

                    if ($existingReport) {
                        $reports[$reportKey] = $existingReport->toArray();
                    } else {
                        $reports[$reportKey] = [
                            'report_key' => $reportKey,
                            'date_at' => $dateAt,
                            'user_id' => $order->user_id,
                            'service_id' => $order->service_id,
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
                    }
                }

                // Nếu order đã được scan trước đó (status thay đổi), trừ giá trị cũ
                if ($order->scanned_at !== null) {
                    // Trừ giá trị cũ dựa vào old_scanned_status
                    $oldStatus = $order->old_scanned_status;
                    if ($oldStatus) {
                        $reports[$reportKey]["order_{$oldStatus}"]--;
                        $reports[$reportKey]['total_quantity'] -= $order->quantity;
                        $reports[$reportKey]['total_charge'] -= $order->charge_amount;
                        $reports[$reportKey]['total_cost'] -= $order->cost_amount;
                        $reports[$reportKey]['total_profit'] -= $order->profit_amount;
                        $reports[$reportKey]['total_refund'] -= $order->refund_amount;
                    }
                }

                // Cộng giá trị mới
                $reports[$reportKey]['total_quantity'] += $order->quantity;
                $reports[$reportKey]['total_charge'] += $order->charge_amount;
                $reports[$reportKey]['total_cost'] += $order->cost_amount;
                $reports[$reportKey]['total_profit'] += $order->profit_amount;
                $reports[$reportKey]['total_refund'] += $order->refund_amount;

                // Đếm theo status
                $statusField = "order_{$order->status}";
                if (isset($reports[$reportKey][$statusField])) {
                    $reports[$reportKey][$statusField]++;
                }

                // Lưu order_id và status hiện tại để update sau
                $orderIds[$order->id] = $order->status;
                $count++;
            } catch (\Throwable $th) {
                $this->error("❌ Lỗi: " . $th->getMessage());
                continue;
            }
        }

        $this->line("✅ Kết thúc xử lý: " . date('H:i:s d-m-Y'));
        $this->line("📝 Tổng orders cần xử lý: {$count}");

        if ($count === 0) {
            $this->info("✅ Không có orders mới cần xử lý.");
            return 0;
        }

        // Lưu reports vào database
        $this->line("💾 Đang lưu báo cáo vào database...");
        $savedCount = 0;

        foreach ($reports as $report) {
            try {
                // Loại bỏ các key không cần thiết
                unset($report['id'], $report['created_at'], $report['updated_at']);

                ReportOrderDaily::updateOrCreate(
                    ['report_key' => $report['report_key']],
                    $report
                );
                $savedCount++;

                if ($savedCount % 100 == 0) {
                    $this->output->write('.');
                }
            } catch (\Throwable $th) {
                $this->error("\n❌ Lỗi lưu report: " . $th->getMessage());
                continue;
            }
        }

        // Update scanned_at và old_scanned_status cho các orders đã xử lý
        if (!empty($orderIds)) {
            $this->newLine();
            $this->line("📌 Đang cập nhật scanned_at cho " . count($orderIds) . " orders...");

            // Update theo batch
            foreach (array_chunk($orderIds, 500, true) as $chunk) {
                foreach ($chunk as $orderId => $status) {
                    Order::where('id', $orderId)->update([
                        'scanned_at' => $now,
                        'old_scanned_status' => $status,
                    ]);
                }
            }
        }

        $this->newLine();
        $this->info("✅ Hoàn thành! Đã cập nhật {$savedCount} báo cáo.");
        $this->line("🏁 Kết thúc: " . date('H:i:s d-m-Y'));

        return 0;
    }
}
