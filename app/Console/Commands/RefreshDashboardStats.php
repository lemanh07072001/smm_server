<?php

namespace App\Console\Commands;

use App\Models\Dongtien;
use App\Models\Order;
use App\Models\ReportDashboardDaily;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class RefreshDashboardStats extends Command
{
    protected $signature = 'dashboard:refresh';

    protected $description = 'Tính lại thống kê dashboard và lưu vào DB + Redis (chạy mỗi 5 phút)';

    /** TTL Redis = 6 phút (buffer 1 phút so với scheduler 5 phút để tránh window miss) */
    private const CACHE_TTL = 360;

    public function handle(): int
    {
        $startTime = microtime(true);

        try {
            // Bước 1: Query orders hôm nay 1 lần duy nhất
            $todayData = $this->queryTodayFromOrders();

            // Bước 2: Ghi vào ReportDashboardDaily (source of truth)
            $this->writeTodayDailyReport($todayData);

            // Bước 3: Cache tất cả periods vào Redis
            $this->cacheAllPeriods($todayData);

            $elapsed = round((microtime(true) - $startTime) * 1000);
            $this->info("dashboard:refresh done in {$elapsed}ms");
        } catch (\Throwable $e) {
            Log::error('dashboard:refresh failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->error('Lỗi: ' . $e->getMessage());
            return 1;
        }

        return 0;
    }

    /**
     * Query toàn bộ data cần thiết cho hôm nay từ DB — chỉ 2 query.
     */
    private function queryTodayFromOrders(): array
    {
        $todayStart = now()->startOfDay();

        // Query 1: orders stats + financial hôm nay
        $orderRow = Order::where('created_at', '>=', $todayStart)
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN status = 'pending'     THEN 1 ELSE 0 END) as order_pending,
                SUM(CASE WHEN status = 'processing'  THEN 1 ELSE 0 END) as order_processing,
                SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as order_in_progress,
                SUM(CASE WHEN status = 'completed'   THEN 1 ELSE 0 END) as order_completed,
                SUM(CASE WHEN status = 'partial'     THEN 1 ELSE 0 END) as order_partial,
                SUM(CASE WHEN status = 'canceled'    THEN 1 ELSE 0 END) as order_canceled,
                SUM(CASE WHEN status = 'refunded'    THEN 1 ELSE 0 END) as order_refunded,
                SUM(CASE WHEN status = 'failed'      THEN 1 ELSE 0 END) as order_failed,
                SUM(CASE WHEN status IN ('in_progress','processing') THEN 1 ELSE 0 END) as running,
                SUM(CASE WHEN status IN ('canceled','failed') THEN 1 ELSE 0 END) as canceled_total,
                SUM(charge_amount) as revenue,
                SUM(cost_amount)   as cost,
                SUM(profit_amount) as profit
            ")->first();

        // Query 2: deposit hôm nay
        $depositRow = Dongtien::where('type', Dongtien::TYPE_DEPOSIT)
            ->where('created_at', '>=', $todayStart)
            ->selectRaw('COUNT(*) as total_deposits, SUM(amount) as deposit_amount')
            ->first();

        // Query 3: khách mới hôm nay
        $newCustomers = \App\Models\User::where('created_at', '>=', $todayStart)->count();

        return [
            'order_row'     => $orderRow,
            'deposit_row'   => $depositRow,
            'new_customers' => $newCustomers,
            'today_start'   => $todayStart,
        ];
    }

    /**
     * Ghi row hôm nay vào ReportDashboardDaily.
     */
    private function writeTodayDailyReport(array $d): void
    {
        $row = $d['order_row'];
        $dep = $d['deposit_row'];
        $dateAt = (int) now()->format('Ymd');

        ReportDashboardDaily::updateOrCreate(
            ['date_at' => $dateAt],
            [
                'total_orders'      => (int)  ($row->total            ?? 0),
                'order_pending'     => (int)  ($row->order_pending    ?? 0),
                'order_processing'  => (int)  ($row->order_processing ?? 0),
                'order_in_progress' => (int)  ($row->order_in_progress?? 0),
                'order_completed'   => (int)  ($row->order_completed  ?? 0),
                'order_partial'     => (int)  ($row->order_partial    ?? 0),
                'order_canceled'    => (int)  ($row->order_canceled   ?? 0),
                'order_refunded'    => (int)  ($row->order_refunded   ?? 0),
                'order_failed'      => (int)  ($row->order_failed     ?? 0),
                'total_revenue'     => (float)($row->revenue          ?? 0),
                'total_charge'      => (float)($row->revenue          ?? 0),
                'total_cost'        => (float)($row->cost             ?? 0),
                'total_profit'      => (float)($row->profit           ?? 0),
                'total_refund'      => 0,
                'new_customers'     => $d['new_customers'],
                'total_deposits'    => (int)  ($dep->total_deposits   ?? 0),
                'deposit_amount'    => (float)($dep->deposit_amount   ?? 0),
            ]
        );

        $this->line("  [DB] date_at={$dateAt} updated");
    }

    /**
     * Tính và cache tất cả periods vào Redis.
     * today dùng lại data đã query — không query DB thêm lần nào.
     * 7days/30days đọc từ ReportDashboardDaily (tối đa 30 rows).
     */
    private function cacheAllPeriods(array $todayData): void
    {
        foreach (['today', '7days', '30days'] as $period) {
            $stats        = $this->buildStats($period, $todayData);
            $financial    = $this->buildFinancial($period, $todayData);
            $revenueChart = $this->buildRevenueChart($period, $todayData);
            $ordersChart  = $this->buildOrdersChart($period, $todayData);

            Cache::put("dashboard:stats:{$period}",          $stats,        self::CACHE_TTL);
            Cache::put("dashboard:financial:{$period}",      $financial,    self::CACHE_TTL);
            Cache::put("dashboard:chart:revenue:{$period}",  $revenueChart, self::CACHE_TTL);
            Cache::put("dashboard:chart:orders:{$period}",   $ordersChart,  self::CACHE_TTL);

            $this->line("  [Redis] period={$period} cached");
        }
    }

    private function buildStats(string $period, array $todayData): array
    {
        if ($period === 'today') {
            $row     = $todayData['order_row'];
            $deposit = (float)($todayData['deposit_row']->deposit_amount ?? 0);

            return [
                'orders' => [
                    'total'     => (int)($row->total          ?? 0),
                    'completed' => (int)($row->order_completed ?? 0),
                    'running'   => (int)($row->running         ?? 0),
                    'partial'   => (int)($row->order_partial   ?? 0),
                    'canceled'  => (int)($row->canceled_total  ?? 0),
                ],
                'financial' => [
                    'total_revenue' => (float)($row->revenue ?? 0),
                    'total_cost'    => (float)($row->cost    ?? 0),
                    'total_profit'  => (float)($row->profit  ?? 0),
                    'total_deposit' => $deposit,
                ],
            ];
        }

        $days     = $period === '7days' ? 7 : 30;
        $fromDate = (int) now()->subDays($days)->format('Ymd');
        $dateAt   = (int) now()->format('Ymd');

        $row = ReportDashboardDaily::where('date_at', '>=', $fromDate)
            ->where('date_at', '<=', $dateAt)
            ->selectRaw("
                SUM(total_orders) as total,
                SUM(order_completed) as completed,
                SUM(order_in_progress + order_processing) as running,
                SUM(order_partial) as partial,
                SUM(order_canceled + order_failed) as canceled,
                SUM(total_revenue) as revenue,
                SUM(total_cost) as cost,
                SUM(total_profit) as profit,
                SUM(deposit_amount) as deposit_amount
            ")->first();

        return [
            'orders' => [
                'total'     => (int)  ($row->total     ?? 0),
                'completed' => (int)  ($row->completed ?? 0),
                'running'   => (int)  ($row->running   ?? 0),
                'partial'   => (int)  ($row->partial   ?? 0),
                'canceled'  => (int)  ($row->canceled  ?? 0),
            ],
            'financial' => [
                'total_revenue' => (float)($row->revenue        ?? 0),
                'total_cost'    => (float)($row->cost           ?? 0),
                'total_profit'  => (float)($row->profit         ?? 0),
                'total_deposit' => (float)($row->deposit_amount ?? 0),
            ],
        ];
    }

    private function buildFinancial(string $period, array $todayData): array
    {
        $calcChange = function (float $cur, float $prev): ?float {
            if ($prev == 0) return null;
            return round((($cur - $prev) / $prev) * 100, 1);
        };

        if ($period === 'today') {
            $row = $todayData['order_row'];
            $cr  = (float)($row->revenue ?? 0);
            $cc  = (float)($row->cost    ?? 0);
            $cp  = (float)($row->profit  ?? 0);

            // Kỳ trước = hôm qua — đọc từ ReportDashboardDaily (đã có)
            $yesterday = (int) now()->subDay()->format('Ymd');
            $prev = ReportDashboardDaily::where('date_at', $yesterday)
                ->selectRaw('total_revenue as revenue, total_cost as cost, total_profit as profit')
                ->first();

            $pr = (float)($prev->revenue ?? 0);
            $pc = (float)($prev->cost    ?? 0);
            $pp = (float)($prev->profit  ?? 0);
        } else {
            $days = $period === '7days' ? 7 : 30;

            $currentRow = ReportDashboardDaily::where('date_at', '>=', (int) now()->subDays($days)->format('Ymd'))
                ->where('date_at', '<=', (int) now()->format('Ymd'))
                ->selectRaw('SUM(total_revenue) as revenue, SUM(total_cost) as cost, SUM(total_profit) as profit')
                ->first();

            $previousRow = ReportDashboardDaily::where('date_at', '>=', (int) now()->subDays($days * 2)->format('Ymd'))
                ->where('date_at', '<=', (int) now()->subDays($days + 1)->format('Ymd'))
                ->selectRaw('SUM(total_revenue) as revenue, SUM(total_cost) as cost, SUM(total_profit) as profit')
                ->first();

            $cr = (float)($currentRow->revenue  ?? 0);
            $cc = (float)($currentRow->cost     ?? 0);
            $cp = (float)($currentRow->profit   ?? 0);
            $pr = (float)($previousRow->revenue ?? 0);
            $pc = (float)($previousRow->cost    ?? 0);
            $pp = (float)($previousRow->profit  ?? 0);
        }

        return [
            'revenue'        => $cr,
            'cost'           => $cc,
            'profit'         => $cp,
            'revenue_change' => $calcChange($cr, $pr),
            'cost_change'    => $calcChange($cc, $pc),
            'profit_change'  => $calcChange($cp, $pp),
        ];
    }

    private function buildRevenueChart(string $period, array $todayData): array
    {
        if ($period === 'today') {
            // Chart theo giờ — vẫn cần query groupBy hour
            return Order::where('created_at', '>=', $todayData['today_start'])
                ->selectRaw("DATE_FORMAT(created_at, '%H:00') as date, SUM(charge_amount) as revenue, SUM(cost_amount) as cost, SUM(profit_amount) as profit")
                ->groupBy('date')->orderBy('date')->get()
                ->map(fn($r) => ['date' => $r->date, 'revenue' => (float)($r->revenue ?? 0), 'cost' => (float)($r->cost ?? 0), 'profit' => (float)($r->profit ?? 0)])
                ->values()->all();
        }

        $days = $period === '7days' ? 7 : 30;

        return ReportDashboardDaily::where('date_at', '>=', (int) now()->subDays($days)->format('Ymd'))
            ->where('date_at', '<=', (int) now()->format('Ymd'))
            ->orderBy('date_at')
            ->get(['date_at', 'total_revenue', 'total_cost', 'total_profit'])
            ->map(fn($r) => [
                'date'    => $this->formatDateAt($r->date_at),
                'revenue' => (float)($r->total_revenue ?? 0),
                'cost'    => (float)($r->total_cost    ?? 0),
                'profit'  => (float)($r->total_profit  ?? 0),
            ])->values()->all();
    }

    private function buildOrdersChart(string $period, array $todayData): array
    {
        if ($period === 'today') {
            return Order::where('created_at', '>=', $todayData['today_start'])
                ->selectRaw("
                    DATE_FORMAT(created_at, '%H:00') as date,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                    SUM(CASE WHEN status IN ('in_progress','processing') THEN 1 ELSE 0 END) as running,
                    SUM(CASE WHEN status = 'partial' THEN 1 ELSE 0 END) as partial,
                    SUM(CASE WHEN status IN ('canceled','failed') THEN 1 ELSE 0 END) as canceled
                ")
                ->groupBy('date')->orderBy('date')->get()
                ->map(fn($r) => ['date' => $r->date, 'completed' => (int)($r->completed ?? 0), 'running' => (int)($r->running ?? 0), 'partial' => (int)($r->partial ?? 0), 'canceled' => (int)($r->canceled ?? 0)])
                ->values()->all();
        }

        $days = $period === '7days' ? 7 : 30;

        return ReportDashboardDaily::where('date_at', '>=', (int) now()->subDays($days)->format('Ymd'))
            ->where('date_at', '<=', (int) now()->format('Ymd'))
            ->orderBy('date_at')
            ->get(['date_at', 'order_completed', 'order_in_progress', 'order_processing', 'order_partial', 'order_canceled', 'order_failed'])
            ->map(fn($r) => [
                'date'      => $this->formatDateAt($r->date_at),
                'completed' => (int)($r->order_completed   ?? 0),
                'running'   => (int)(($r->order_in_progress ?? 0) + ($r->order_processing ?? 0)),
                'partial'   => (int)($r->order_partial     ?? 0),
                'canceled'  => (int)(($r->order_canceled   ?? 0) + ($r->order_failed ?? 0)),
            ])->values()->all();
    }

    /** Chuyển YYYYMMDD integer thành 'YYYY-MM-DD' string */
    private function formatDateAt(int $dateAt): string
    {
        $s = (string) $dateAt;
        return substr($s, 0, 4) . '-' . substr($s, 4, 2) . '-' . substr($s, 6, 2);
    }
}
