<?php

namespace App\Console\Commands;

use App\Models\Dongtien;
use App\Models\Order;
use App\Models\ReportDashboardDaily;
use App\Models\ReportOrderDaily;
use App\Models\UserFinancialReport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class RefreshAllStats extends Command
{
    protected $signature = 'report:refresh';

    protected $description = 'Quét orders + dongtien scan=0, cập nhật report tables và cache dashboard (chạy mỗi 5 phút)';

    private const CACHE_TTL = 360;

    public function handle(): int
    {
        $startTime = microtime(true);

        try {
            $this->processOrderReport();
            $this->processUserFinancialReport();
            $this->refreshDashboard();

            $elapsed = round((microtime(true) - $startTime) * 1000);
            $this->info("report:refresh done in {$elapsed}ms");
        } catch (\Throwable $e) {
            Log::error('report:refresh failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->error('Lỗi: ' . $e->getMessage());
            return 1;
        }

        return 0;
    }

    // ─── Step 1: Order Report ─────────────────────────────────────────────────

    private function processOrderReport(): void
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
            $this->line('  [orders] không có đơn mới');
            return;
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

        Order::whereIn('id', $orders->pluck('id')->all())->update(['scan' => 1]);

        $this->line('  [orders] đã xử lý ' . $orders->count() . ' đơn');
    }

    // ─── Step 2: User Financial Report ───────────────────────────────────────

    private function processUserFinancialReport(): void
    {
        $transactions = Dongtien::where('scan', 0)
            ->whereIn('type', [Dongtien::TYPE_DEPOSIT, Dongtien::TYPE_CHARGE, Dongtien::TYPE_REFUND, 'withdraw'])
            ->orderBy('id')
            ->get();

        if ($transactions->isEmpty()) {
            $this->line('  [financial] không có giao dịch mới');
            return;
        }

        $userStats = [];

        foreach ($transactions as $transaction) {
            $dateAt = strtotime(date('Y-m-d', strtotime($transaction->created_at)));
            $key    = "{$transaction->user_id}_{$dateAt}";

            if (!isset($userStats[$key])) {
                $userStats[$key] = [
                    'user_id'         => $transaction->user_id,
                    'date_at'         => $dateAt,
                    'total_deposit'   => 0,
                    'total_spending'  => 0,
                    'total_refund'    => 0,
                    'total_withdraw'  => 0,
                ];
            }

            switch ($transaction->type) {
                case Dongtien::TYPE_DEPOSIT:
                case 'deposit':
                    $userStats[$key]['total_deposit']  += abs($transaction->amount);
                    break;
                case Dongtien::TYPE_CHARGE:
                case 'charge':
                    $userStats[$key]['total_spending'] += abs($transaction->amount);
                    break;
                case Dongtien::TYPE_REFUND:
                case 'refund':
                    $userStats[$key]['total_refund']   += abs($transaction->amount);
                    break;
                case 'withdraw':
                    $userStats[$key]['total_withdraw'] += abs($transaction->amount);
                    break;
            }
        }

        foreach ($userStats as $stats) {
            $report = UserFinancialReport::firstOrNew([
                'user_id' => $stats['user_id'],
                'date_at' => $stats['date_at'],
            ]);

            $report->total_deposit   += $stats['total_deposit'];
            $report->total_spending  += $stats['total_spending'];
            $report->total_refund    += $stats['total_refund'];
            $report->total_withdraw  += $stats['total_withdraw'];

            $user = \App\Models\User::find($stats['user_id']);
            if ($user) {
                $report->current_balance = $user->balance;
            }

            $report->save();
        }

        Dongtien::whereIn('id', $transactions->pluck('id')->all())->update(['scan' => 1]);

        $this->line('  [financial] đã xử lý ' . $transactions->count() . ' giao dịch');
    }

    // ─── Step 3: Dashboard Refresh ────────────────────────────────────────────

    private function refreshDashboard(): void
    {
        $todayData = $this->queryToday();

        $this->writeTodayDailyReport($todayData);

        $this->cacheAllPeriods($todayData);

        $this->line('  [dashboard] cache updated');
    }

    private function queryToday(): array
    {
        $todayStart = now()->startOfDay();

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

        $depositRow = Dongtien::where('type', Dongtien::TYPE_DEPOSIT)
            ->where('created_at', '>=', $todayStart)
            ->selectRaw('COUNT(*) as total_deposits, SUM(amount) as deposit_amount')
            ->first();

        $newCustomers = \App\Models\User::where('created_at', '>=', $todayStart)->count();

        return [
            'order_row'     => $orderRow,
            'deposit_row'   => $depositRow,
            'new_customers' => $newCustomers,
            'today_start'   => $todayStart,
        ];
    }

    private function writeTodayDailyReport(array $d): void
    {
        $row    = $d['order_row'];
        $dep    = $d['deposit_row'];
        $dateAt = (int) now()->format('Ymd');

        ReportDashboardDaily::updateOrCreate(
            ['date_at' => $dateAt],
            [
                'total_orders'      => (int)  ($row->total             ?? 0),
                'order_pending'     => (int)  ($row->order_pending     ?? 0),
                'order_processing'  => (int)  ($row->order_processing  ?? 0),
                'order_in_progress' => (int)  ($row->order_in_progress ?? 0),
                'order_completed'   => (int)  ($row->order_completed   ?? 0),
                'order_partial'     => (int)  ($row->order_partial     ?? 0),
                'order_canceled'    => (int)  ($row->order_canceled    ?? 0),
                'order_refunded'    => (int)  ($row->order_refunded    ?? 0),
                'order_failed'      => (int)  ($row->order_failed      ?? 0),
                'total_revenue'     => (float)($row->revenue           ?? 0),
                'total_charge'      => (float)($row->revenue           ?? 0),
                'total_cost'        => (float)($row->cost              ?? 0),
                'total_profit'      => (float)($row->profit            ?? 0),
                'total_refund'      => 0,
                'new_customers'     => $d['new_customers'],
                'total_deposits'    => (int)  ($dep->total_deposits    ?? 0),
                'deposit_amount'    => (float)($dep->deposit_amount    ?? 0),
            ]
        );
    }

    private function cacheAllPeriods(array $todayData): void
    {
        foreach (['today', '7days', '30days'] as $period) {
            Cache::put("dashboard:stats:{$period}",         $this->buildStats($period, $todayData),        self::CACHE_TTL);
            Cache::put("dashboard:financial:{$period}",     $this->buildFinancial($period, $todayData),    self::CACHE_TTL);
            Cache::put("dashboard:chart:revenue:{$period}", $this->buildRevenueChart($period, $todayData), self::CACHE_TTL);
            Cache::put("dashboard:chart:orders:{$period}",  $this->buildOrdersChart($period, $todayData),  self::CACHE_TTL);
        }
    }

    private function buildStats(string $period, array $todayData): array
    {
        if ($period === 'today') {
            $row     = $todayData['order_row'];
            $deposit = (float)($todayData['deposit_row']->deposit_amount ?? 0);

            return [
                'orders' => [
                    'total'     => (int)($row->total           ?? 0),
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

        $days          = $period === '7days' ? 7 : 30;
        $fromTimestamp = now()->subDays($days)->startOfDay()->timestamp;
        $toTimestamp   = now()->endOfDay()->timestamp;

        $row = ReportOrderDaily::where('date_at', '>=', $fromTimestamp)
            ->where('date_at', '<=', $toTimestamp)
            ->selectRaw("
                SUM(order_completed) as completed,
                SUM(order_partial)   as partial,
                SUM(order_canceled + order_failed) as canceled,
                SUM(total_charge)  as revenue,
                SUM(total_cost)    as cost,
                SUM(total_profit)  as profit
            ")->first();

        $depositAmount = Dongtien::where('type', Dongtien::TYPE_DEPOSIT)
            ->where('created_at', '>=', now()->subDays($days)->startOfDay())
            ->sum('amount');

        $completed = (int)($row->completed ?? 0);
        $partial   = (int)($row->partial   ?? 0);
        $canceled  = (int)($row->canceled  ?? 0);

        return [
            'orders' => [
                'total'     => $completed + $partial + $canceled,
                'completed' => $completed,
                'partial'   => $partial,
                'canceled'  => $canceled,
            ],
            'financial' => [
                'total_revenue' => (float)($row->revenue ?? 0),
                'total_cost'    => (float)($row->cost    ?? 0),
                'total_profit'  => (float)($row->profit  ?? 0),
                'total_deposit' => (float) $depositAmount,
            ],
        ];
    }

    private function buildFinancial(string $period, array $todayData): array
    {
        $calcChange = function (float $current, float $previous): ?float {
            if ($previous == 0) return null;
            return round((($current - $previous) / $previous) * 100, 1);
        };

        if ($period === 'today') {
            $row = $todayData['order_row'];
            $cr  = (float)($row->revenue ?? 0);
            $cc  = (float)($row->cost    ?? 0);
            $cp  = (float)($row->profit  ?? 0);

            $yesterday = now()->subDay();
            $prev = ReportOrderDaily::where('date_at', '>=', $yesterday->startOfDay()->timestamp)
                ->where('date_at', '<=', $yesterday->endOfDay()->timestamp)
                ->selectRaw('SUM(total_charge) as revenue, SUM(total_cost) as cost, SUM(total_profit) as profit')
                ->first();

            $pr = (float)($prev->revenue ?? 0);
            $pc = (float)($prev->cost    ?? 0);
            $pp = (float)($prev->profit  ?? 0);
        } else {
            $days = $period === '7days' ? 7 : 30;

            $currentFrom  = now()->subDays($days)->startOfDay()->timestamp;
            $currentTo    = now()->endOfDay()->timestamp;
            $previousFrom = now()->subDays($days * 2)->startOfDay()->timestamp;
            $previousTo   = now()->subDays($days + 1)->endOfDay()->timestamp;

            $currentRow = ReportOrderDaily::where('date_at', '>=', $currentFrom)
                ->where('date_at', '<=', $currentTo)
                ->selectRaw('SUM(total_charge) as revenue, SUM(total_cost) as cost, SUM(total_profit) as profit')
                ->first();

            $previousRow = ReportOrderDaily::where('date_at', '>=', $previousFrom)
                ->where('date_at', '<=', $previousTo)
                ->selectRaw('SUM(total_charge) as revenue, SUM(total_cost) as cost, SUM(total_profit) as profit')
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
            return Order::where('created_at', '>=', $todayData['today_start'])
                ->selectRaw("DATE_FORMAT(created_at, '%H:00') as date, SUM(charge_amount) as revenue, SUM(cost_amount) as cost, SUM(profit_amount) as profit")
                ->groupBy('date')->orderBy('date')->get()
                ->map(fn($r) => [
                    'date'    => $r->date,
                    'revenue' => (float)($r->revenue ?? 0),
                    'cost'    => (float)($r->cost    ?? 0),
                    'profit'  => (float)($r->profit  ?? 0),
                ])->values()->all();
        }

        $days = $period === '7days' ? 7 : 30;

        return ReportOrderDaily::where('date_at', '>=', now()->subDays($days)->startOfDay()->timestamp)
            ->where('date_at', '<=', now()->endOfDay()->timestamp)
            ->selectRaw('date_at, SUM(total_charge) as revenue, SUM(total_cost) as cost, SUM(total_profit) as profit')
            ->groupBy('date_at')
            ->orderBy('date_at')
            ->get()
            ->map(fn($r) => [
                'date'    => date('Y-m-d', $r->date_at),
                'revenue' => (float)($r->revenue ?? 0),
                'cost'    => (float)($r->cost    ?? 0),
                'profit'  => (float)($r->profit  ?? 0),
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
                ->map(fn($r) => [
                    'date'      => $r->date,
                    'completed' => (int)($r->completed ?? 0),
                    'running'   => (int)($r->running   ?? 0),
                    'partial'   => (int)($r->partial   ?? 0),
                    'canceled'  => (int)($r->canceled  ?? 0),
                ])->values()->all();
        }

        $days = $period === '7days' ? 7 : 30;

        return ReportOrderDaily::where('date_at', '>=', now()->subDays($days)->startOfDay()->timestamp)
            ->where('date_at', '<=', now()->endOfDay()->timestamp)
            ->selectRaw('date_at, SUM(order_completed) as completed, SUM(order_partial) as partial, SUM(order_canceled + order_failed) as canceled')
            ->groupBy('date_at')
            ->orderBy('date_at')
            ->get()
            ->map(fn($r) => [
                'date'      => date('Y-m-d', $r->date_at),
                'completed' => (int)($r->completed ?? 0),
                'partial'   => (int)($r->partial   ?? 0),
                'canceled'  => (int)($r->canceled  ?? 0),
            ])->values()->all();
    }
}
