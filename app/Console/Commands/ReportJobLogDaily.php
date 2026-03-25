<?php

namespace App\Console\Commands;

use App\Models\JobLog;
use App\Models\ReportOrderDailyJob;
use App\Models\ReportRevenueUserDaily;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ReportJobLogDaily extends Command
{
    protected $signature = 'report:job-log-daily {date? : Ngày cần tổng hợp (Y-m-d)} {--all : Tổng hợp tất cả các ngày có dữ liệu}';

    protected $description = 'Tổng hợp job log theo ngày, ghi vào 2 bảng báo cáo: report_order_dailies, report_revenue_user_dailies';

    public function handle(): int
    {
        if ($this->option('all')) {
            return $this->runAll();
        }

        $dateInput = $this->argument('date');

        if (! $dateInput) {
            $this->error('Vui lòng truyền {date} hoặc dùng --all');
            return 1;
        }

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateInput) || ! strtotime($dateInput)) {
            $this->error("Ngày không hợp lệ: {$dateInput}. Định dạng yêu cầu: Y-m-d");
            return 1;
        }

        $this->info("[start] report:job-log-daily {$dateInput}");
        $this->processDate($dateInput);

        return 0;
    }

    private function processDate(string $dateInput): void
    {
        $orderBuckets   = [];
        $revenueBuckets = [];

        $startOfDay = $dateInput . ' 00:00:00';
        $endOfDay   = $dateInput . ' 23:59:59';

        // ─── Query ───────────────────────────────────────────────────────────────

        $this->line('  [query] bắt đầu đọc job_logs...');

        $query = JobLog::whereBetween('date_create', [$startOfDay, $endOfDay])
            ->select(['order_id', 'user_id', 'tool', 'status', 'price_buyer', 'price_system', 'date_create']);

        $totalRead  = 0;
        $errorCount = 0;

        // ─── Aggregate ───────────────────────────────────────────────────────────

        foreach ($query->cursor() as $log) {
            try {
                $this->aggregateOrderBucket($orderBuckets, $dateInput, $log);
                $this->aggregateRevenueBucket($revenueBuckets, $dateInput, $log);
                $totalRead++;
            } catch (\Throwable $e) {
                $errorCount++;
                Log::error('report:job-log-daily aggregate error', [
                    'date'     => $dateInput,
                    'log_data' => ['order_id' => $log->order_id, 'user_id' => $log->user_id, 'status' => $log->status],
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        $this->line("  [query] đọc xong {$totalRead} records" . ($errorCount ? ", bỏ qua {$errorCount} lỗi" : ''));

        // ─── Ghi DB ──────────────────────────────────────────────────────────────

        $this->line('  [write] bắt đầu ghi report_order_dailies...');
        $this->writeOrderBuckets($orderBuckets, $dateInput);
        $this->line('  [write] xong report_order_dailies (' . count($orderBuckets) . ' records)');

        $this->line('  [write] bắt đầu ghi report_revenue_user_dailies...');
        $this->writeRevenueBuckets($revenueBuckets, $dateInput);
        $this->line('  [write] xong report_revenue_user_dailies (' . count($revenueBuckets) . ' records)');

        $this->info("[done] report:job-log-daily {$dateInput}");
    }

    // ─── All-dates runner ────────────────────────────────────────────────────────

    private function runAll(): int
    {
        $minDate = JobLog::min('date_create');
        $maxDate = JobLog::max('date_create');

        if (! $minDate) {
            $this->warn('Không có dữ liệu trong job_logs');
            return 0;
        }

        $current = new \DateTime(date('Y-m-d', strtotime($minDate)));
        $last    = new \DateTime(date('Y-m-d', strtotime($maxDate)));

        $this->info("[--all] từ {$current->format('Y-m-d')} đến {$last->format('Y-m-d')}");

        while ($current <= $last) {
            $dateInput = $current->format('Y-m-d');
            $this->info("[start] report:job-log-daily {$dateInput}");
            $this->processDate($dateInput);
            $current->modify('+1 day');
        }

        $this->info('[--all] hoàn tất');

        return 0;
    }

    // ─── Aggregate helpers ───────────────────────────────────────────────────────

    private function aggregateOrderBucket(array &$buckets, string $dateInput, JobLog $log): void
    {
        $key = "{$dateInput}_{$log->order_id}_{$log->user_id}";

        if (! isset($buckets[$key])) {
            $buckets[$key] = $this->emptyOrderBucket($dateInput, $log->order_id, $log->user_id);
        }

        $statusField = 'job_' . $log->status;
        if (isset($buckets[$key][$statusField])) {
            $buckets[$key][$statusField]++;
        }

        $buyer  = (float) $log->price_buyer;
        $system = (float) $log->price_system;

        switch ($log->status) {
            case JobLog::STATUS_COMPLETE:
                $buckets[$key]['pending_buyer']  += $buyer;
                $buckets[$key]['pending_system'] += $system;
                break;
            case JobLog::STATUS_APPROVED:
                $buckets[$key]['approved_buyer']  += $buyer;
                $buckets[$key]['approved_system'] += $system;
                break;
            case JobLog::STATUS_REJECT:
                $buckets[$key]['rejected_buyer']  += $buyer;
                $buckets[$key]['rejected_system'] += $system;
                break;
            case JobLog::STATUS_WAITING_CHECK:
                $buckets[$key]['waiting_buyer']  += $buyer;
                $buckets[$key]['waiting_system'] += $system;
                break;
        }
    }

    private function aggregateRevenueBucket(array &$buckets, string $dateInput, JobLog $log): void
    {
        $key = "{$dateInput}_{$log->user_id}";

        if (! isset($buckets[$key])) {
            $buckets[$key] = $this->emptyRevenueBucket($dateInput, $log->user_id);
        }

        $system = (float) $log->price_system;

        switch ($log->status) {
            case JobLog::STATUS_COMPLETE:
                $buckets[$key]['system_complete'] += $system;
                break;
            case JobLog::STATUS_WAITING_CHECK:
                $buckets[$key]['system_waiting_check'] += $system;
                break;
            case JobLog::STATUS_APPROVED:
                $buckets[$key]['system_approved'] += $system;
                break;
            case JobLog::STATUS_REJECT:
                $buckets[$key]['system_rejected'] += $system;
                break;
            case JobLog::STATUS_EXPIRED:
                $buckets[$key]['system_expired'] += $system;
                break;
        }
    }

    // ─── Write helpers ───────────────────────────────────────────────────────────

    private function writeOrderBuckets(array $buckets, string $dateInput): void
    {
        foreach ($buckets as $bucket) {
            try {
                ReportOrderDailyJob::updateOrCreate(
                    [
                        'date_at'  => $bucket['date_at'],
                        'order_id' => $bucket['order_id'],
                        'user_id'  => $bucket['user_id'],
                    ],
                    $bucket
                );
            } catch (\Throwable $e) {
                Log::error('report:job-log-daily write order bucket error', [
                    'date'     => $dateInput,
                    'order_id' => $bucket['order_id'],
                    'user_id'  => $bucket['user_id'],
                    'error'    => $e->getMessage(),
                ]);
            }
        }
    }

    private function writeRevenueBuckets(array $buckets, string $dateInput): void
    {
        foreach ($buckets as $bucket) {
            try {
                ReportRevenueUserDaily::updateOrCreate(
                    [
                        'date_at' => $bucket['date_at'],
                        'user_id' => $bucket['user_id'],
                    ],
                    $bucket
                );
            } catch (\Throwable $e) {
                Log::error('report:job-log-daily write revenue bucket error', [
                    'date'    => $dateInput,
                    'user_id' => $bucket['user_id'],
                    'error'   => $e->getMessage(),
                ]);
            }
        }
    }

    // ─── Empty bucket factories ──────────────────────────────────────────────────

    private function emptyOrderBucket(string $dateInput, int $orderId, int $userId): array
    {
        return [
            'date_at'           => $dateInput,
            'order_id'          => $orderId,
            'user_id'           => $userId,
            'job_pending'       => 0,
            'job_complete'      => 0,
            'job_error'         => 0,
            'job_reject'        => 0,
            'job_approved'      => 0,
            'job_expired'       => 0,
            'job_waiting_check' => 0,
            'pending_buyer'     => 0.0,
            'pending_system'    => 0.0,
            'approved_buyer'    => 0.0,
            'approved_system'   => 0.0,
            'rejected_buyer'    => 0.0,
            'rejected_system'   => 0.0,
            'waiting_buyer'     => 0.0,
            'waiting_system'    => 0.0,
        ];
    }

    private function emptyRevenueBucket(string $dateInput, int $userId): array
    {
        return [
            'date_at'              => $dateInput,
            'user_id'              => $userId,
            'system_complete'      => 0.0,
            'system_waiting_check' => 0.0,
            'system_approved'      => 0.0,
            'system_rejected'      => 0.0,
            'system_expired'       => 0.0,
        ];
    }
}
