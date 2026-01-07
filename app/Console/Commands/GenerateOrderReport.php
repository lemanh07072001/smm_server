<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\ReportOrderDaily;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class GenerateOrderReport extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'report:order {--full : Tính lại toàn bộ thay vì chỉ orders mới/thay đổi}';

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
        $isFull = $this->option('full');
        $now = now();

        $this->info("🚀 Bắt đầu thống kê: " . $now->format('H:i:s d-m-Y'));

        // Query orders mới hoặc đã thay đổi
        $this->line("📊 Đang query orders...");

        $query = Order::query();

        if (!$isFull) {
            // Chỉ lấy orders chưa scan hoặc đã thay đổi sau lần scan cuối
            $query->where(function ($q) {
                $q->whereNull('scanned_at')
                  ->orWhereColumn('updated_at', '>', 'scanned_at');
            });
        }

        $orders = $query->cursor();

        $this->line("✅ Kết thúc query: " . date('H:i:s d-m-Y'));

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

                // Nếu order đã được scan trước đó, trừ đi giá trị cũ trước
                if ($order->scanned_at !== null) {
                    // Trừ giá trị cũ (dựa vào old_status nếu có, hoặc giả định pending)
                    // Vì không lưu old_status, ta cần tính lại toàn bộ report cho key này
                    // Đánh dấu cần recalculate
                    $reports[$reportKey]['_needs_recalc'] = true;
                }

                // Lưu order_id để update scanned_at sau
                $orderIds[] = $order->id;
                $count++;
            } catch (\Throwable $th) {
                $this->error("❌ Lỗi: " . $th->getMessage());
                continue;
            }
        }

        $this->line("✅ Kết thúc xử lý: " . date('H:i:s d-m-Y'));
        $this->line("📝 Tổng orders cần xử lý: {$count}");

        // Với các report cần recalculate, tính lại từ đầu
        $this->line("🔄 Đang tính toán lại các report...");

        $reportsToRecalc = array_filter($reports, fn($r) => isset($r['_needs_recalc']) && $r['_needs_recalc']);

        if (!empty($reportsToRecalc) || $isFull) {
            // Lấy danh sách các key cần tính lại
            $keysToRecalc = $isFull
                ? array_keys($reports)
                : array_keys($reportsToRecalc);

            foreach ($keysToRecalc as $reportKey) {
                $report = $reports[$reportKey];

                // Tính lại từ database
                $recalcData = Order::select([
                        DB::raw("SUM(quantity) as total_quantity"),
                        DB::raw("SUM(charge_amount) as total_charge"),
                        DB::raw("SUM(cost_amount) as total_cost"),
                        DB::raw("SUM(profit_amount) as total_profit"),
                        DB::raw("SUM(refund_amount) as total_refund"),
                        DB::raw("SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as order_pending"),
                        DB::raw("SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as order_processing"),
                        DB::raw("SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as order_in_progress"),
                        DB::raw("SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as order_completed"),
                        DB::raw("SUM(CASE WHEN status = 'partial' THEN 1 ELSE 0 END) as order_partial"),
                        DB::raw("SUM(CASE WHEN status = 'canceled' THEN 1 ELSE 0 END) as order_canceled"),
                        DB::raw("SUM(CASE WHEN status = 'refunded' THEN 1 ELSE 0 END) as order_refunded"),
                        DB::raw("SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as order_failed"),
                    ])
                    ->whereRaw("DATE_FORMAT(created_at, '%Y%m%d') = ?", [$report['date_at']])
                    ->where('user_id', $report['user_id'])
                    ->where('service_id', $report['service_id'])
                    ->first();

                if ($recalcData) {
                    $reports[$reportKey] = array_merge($reports[$reportKey], [
                        'total_quantity' => $recalcData->total_quantity ?? 0,
                        'total_charge' => $recalcData->total_charge ?? 0,
                        'total_cost' => $recalcData->total_cost ?? 0,
                        'total_profit' => $recalcData->total_profit ?? 0,
                        'total_refund' => $recalcData->total_refund ?? 0,
                        'order_pending' => $recalcData->order_pending ?? 0,
                        'order_processing' => $recalcData->order_processing ?? 0,
                        'order_in_progress' => $recalcData->order_in_progress ?? 0,
                        'order_completed' => $recalcData->order_completed ?? 0,
                        'order_partial' => $recalcData->order_partial ?? 0,
                        'order_canceled' => $recalcData->order_canceled ?? 0,
                        'order_refunded' => $recalcData->order_refunded ?? 0,
                        'order_failed' => $recalcData->order_failed ?? 0,
                    ]);
                }

                unset($reports[$reportKey]['_needs_recalc']);
            }
        }

        // Lưu reports vào database
        $this->line("💾 Đang lưu báo cáo vào database...");
        $savedCount = 0;

        foreach ($reports as $report) {
            try {
                // Loại bỏ các key không cần thiết
                unset($report['id'], $report['created_at'], $report['updated_at'], $report['_needs_recalc']);

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

        // Update scanned_at cho các orders đã xử lý
        if (!empty($orderIds)) {
            $this->newLine();
            $this->line("📌 Đang cập nhật scanned_at cho " . count($orderIds) . " orders...");

            // Update theo batch để tránh query quá dài
            $chunks = array_chunk($orderIds, 1000);
            foreach ($chunks as $chunk) {
                Order::whereIn('id', $chunk)->update(['scanned_at' => $now]);
            }
        }

        $this->newLine();
        $this->info("✅ Hoàn thành! Đã cập nhật {$savedCount} báo cáo.");
        $this->line("🏁 Kết thúc: " . date('H:i:s d-m-Y'));

        return 0;
    }
}
