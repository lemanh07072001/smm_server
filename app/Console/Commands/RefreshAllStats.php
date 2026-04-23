<?php

namespace App\Console\Commands;

use App\Models\Dongtien;
use App\Models\Order;
use App\Models\ReportDashboardDaily;
use App\Models\ReportOrderDaily;
use App\Models\User;
use App\Models\UserFinancialReport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class RefreshAllStats extends Command
{
    protected $signature = 'report:refresh';

    protected $description = 'Quét orders + dongtien scan=0, cộng dồn vào report tables (chạy mỗi giờ)';

    private const EXCLUDED_USER_IDS = [1, 16011];

    private const CACHE_TTL = 360;

    private const TERMINAL_STATUSES = [
        Order::STATUS_COMPLETED,
        Order::STATUS_PARTIAL,
        Order::STATUS_CANCELED,
        Order::STATUS_FAILED,
    ];

    private const SCANNABLE_DONGTIEN_TYPES = [
        Dongtien::TYPE_DEPOSIT,
        Dongtien::TYPE_ADJUSTMENT,
        Dongtien::TYPE_CHARGE,
        Dongtien::TYPE_REFUND,
        'withdraw',
    ];

    public function handle(): int
    {
        $startTime = microtime(true);

        $orderCount       = $this->processOrderReport();
        $transactionCount = $this->processUserFinancialReport();
        $this->refreshDashboardCache();

        $elapsed = round((microtime(true) - $startTime) * 1000);
        $this->info("report:refresh done in {$elapsed}ms — orders: {$orderCount}, transactions: {$transactionCount}");

        return 0;
    }

    // ─── Bước 1: Order Report ─────────────────────────────────────────────────

    private function processOrderReport(): int
    {
        $orders = Order::whereIn('status', self::TERMINAL_STATUSES)
            ->where('scan', 0)
            ->whereNotIn('user_id', self::EXCLUDED_USER_IDS)
            ->get(['id', 'user_id', 'service_id', 'status', 'created_at',
                   'charge_amount', 'cost_amount', 'profit_amount', 'refund_amount', 'quantity']);

        if ($orders->isEmpty()) {
            return 0;
        }

        // Aggregate trong PHP theo user_id + service_id + date
        $groups = [];

        foreach ($orders as $order) {
            $dateAt = strtotime(date('Y-m-d', strtotime($order->created_at)));
            $key    = "{$order->user_id}_{$order->service_id}_{$dateAt}";

            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'user_id'           => $order->user_id,
                    'service_id'        => $order->service_id,
                    'date_at'           => $dateAt,
                    'order_completed'   => 0,
                    'order_partial'     => 0,
                    'order_canceled'    => 0,
                    'order_failed'      => 0,
                    'total_charge'      => 0,
                    'total_cost'        => 0,
                    'total_profit'      => 0,
                    'total_refund'      => 0,
                    'total_quantity'    => 0,
                ];
            }

            $statusField = "order_{$order->status}";
            if (array_key_exists($statusField, $groups[$key])) {
                $groups[$key][$statusField]++;
            }

            $groups[$key]['total_quantity'] += $order->quantity;

            // FAILED và CANCELED: hoàn toàn bộ → không tính vào total_charge (tránh thổi phồng doanh thu)
            if (in_array($order->status, [Order::STATUS_FAILED, Order::STATUS_CANCELED])) {
                $groups[$key]['total_refund'] += (float) $order->charge_amount;
            } else {
                // COMPLETED, PARTIAL: tính doanh thu; partial có thêm refund_amount
                $groups[$key]['total_charge']  += (float) $order->charge_amount;
                $groups[$key]['total_cost']    += (float) $order->cost_amount;
                $groups[$key]['total_profit']  += (float) $order->profit_amount;
                $groups[$key]['total_refund']  += (float) $order->refund_amount;
            }
        }

        DB::transaction(function () use ($groups, $orders) {
            foreach ($groups as $group) {
                $report = ReportOrderDaily::firstOrNew([
                    'user_id'    => $group['user_id'],
                    'service_id' => $group['service_id'],
                    'date_at'    => $group['date_at'],
                ]);

                $report->order_completed   = ($report->order_completed   ?? 0) + $group['order_completed'];
                $report->order_partial     = ($report->order_partial     ?? 0) + $group['order_partial'];
                $report->order_canceled    = ($report->order_canceled    ?? 0) + $group['order_canceled'];
                $report->order_failed      = ($report->order_failed      ?? 0) + $group['order_failed'];
                $report->total_charge      = ($report->total_charge      ?? 0) + $group['total_charge'];
                $report->total_cost        = ($report->total_cost        ?? 0) + $group['total_cost'];
                $report->total_profit      = ($report->total_profit      ?? 0) + $group['total_profit'];
                $report->total_refund      = ($report->total_refund      ?? 0) + $group['total_refund'];
                $report->total_quantity    = ($report->total_quantity    ?? 0) + $group['total_quantity'];

                $report->save();
            }

            Order::whereIn('id', $orders->pluck('id')->all())->update(['scan' => 1]);
        });

        return $orders->count();
    }

    // ─── Bước 2: User Financial Report ───────────────────────────────────────

    private function processUserFinancialReport(): int
    {
        $transactions = Dongtien::where('scan', 0)
            ->whereIn('type', self::SCANNABLE_DONGTIEN_TYPES)
            ->whereNotIn('user_id', self::EXCLUDED_USER_IDS)
            ->orderBy('id')
            ->get(['id', 'user_id', 'type', 'amount', 'created_at']);

        if ($transactions->isEmpty()) {
            return 0;
        }

        // Aggregate trong PHP theo user_id + date
        $groups = [];

        foreach ($transactions as $transaction) {
            $dateAt = strtotime(date('Y-m-d', strtotime($transaction->created_at)));
            $key    = "{$transaction->user_id}_{$dateAt}";

            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'user_id'        => $transaction->user_id,
                    'date_at'        => $dateAt,
                    'total_deposit'  => 0,
                    'total_spending' => 0,
                    'total_refund'   => 0,
                    'total_withdraw' => 0,
                ];
            }

            $amount = abs((float) $transaction->amount);

            switch ($transaction->type) {
                case Dongtien::TYPE_DEPOSIT:
                case Dongtien::TYPE_ADJUSTMENT:
                    $groups[$key]['total_deposit']  += $amount;
                    break;
                case Dongtien::TYPE_CHARGE:
                    $groups[$key]['total_spending'] += $amount;
                    break;
                case Dongtien::TYPE_REFUND:
                    $groups[$key]['total_refund']   += $amount;
                    break;
                case 'withdraw':
                    $groups[$key]['total_withdraw'] += $amount;
                    break;
            }
        }

        DB::transaction(function () use ($groups, $transactions) {
            foreach ($groups as $group) {
                $report = UserFinancialReport::firstOrNew([
                    'user_id' => $group['user_id'],
                    'date_at' => $group['date_at'],
                ]);

                $report->total_deposit   = ($report->total_deposit   ?? 0) + $group['total_deposit'];
                $report->total_spending  = ($report->total_spending  ?? 0) + $group['total_spending'];
                $report->total_refund    = ($report->total_refund    ?? 0) + $group['total_refund'];
                $report->total_withdraw  = ($report->total_withdraw  ?? 0) + $group['total_withdraw'];
                $report->current_balance = (float) \App\Models\User::where('id', $group['user_id'])->value('balance');

                $report->save();
            }

            Dongtien::whereIn('id', $transactions->pluck('id')->all())->update(['scan' => 1]);
        });

        return $transactions->count();
    }

    // ─── Bước 3: Dashboard Cache ──────────────────────────────────────────────

    private function refreshDashboardCache(): void
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

        $depositRow = Dongtien::whereIn('type', [Dongtien::TYPE_DEPOSIT, Dongtien::TYPE_ADJUSTMENT])
            ->where('created_at', '>=', $todayStart)
            ->selectRaw('COUNT(*) as total_deposits, SUM(amount) as deposit_amount')
            ->first();

        $refundAmount = Dongtien::where('type', Dongtien::TYPE_REFUND)
            ->where('created_at', '>=', $todayStart)
            ->sum('amount');

        $newCustomers = User::where('created_at', '>=', $todayStart)->count();

        $todayData = [
            'order_row'     => $orderRow,
            'deposit_row'   => $depositRow,
            'refund_amount' => $refundAmount,
            'new_customers' => $newCustomers,
            'today_start'   => $todayStart,
        ];

        $this->writeTodayDailyReport($todayData);

        foreach (['today', '7days', '30days'] as $period) {
            Cache::put("dashboard:stats:{$period}",         $this->buildStats($period, $todayData),        self::CACHE_TTL);
            Cache::put("dashboard:financial:{$period}",     $this->buildFinancial($period, $todayData),    self::CACHE_TTL);
            Cache::put("dashboard:chart:revenue:{$period}", $this->buildRevenueChart($period, $todayData), self::CACHE_TTL);
            Cache::put("dashboard:chart:orders:{$period}",  $this->buildOrdersChart($period, $todayData),  self::CACHE_TTL);
        }
    }

    private function writeTodayDailyReport(array $d): void
    {
        $row    = $d['order_row'];
        $dep    = $d['deposit_row'];
        $dateAt = (int) now()->format('Ymd');

        ReportDashboardDaily::updateOrCreate(
            ['date_at' => $dateAt],
            [
                'total_orders'      => (int)   ($row->total             ?? 0),
                'order_pending'     => (int)   ($row->order_pending     ?? 0),
                'order_processing'  => (int)   ($row->order_processing  ?? 0),
                'order_in_progress' => (int)   ($row->order_in_progress ?? 0),
                'order_completed'   => (int)   ($row->order_completed   ?? 0),
                'order_partial'     => (int)   ($row->order_partial     ?? 0),
                'order_canceled'    => (int)   ($row->order_canceled    ?? 0),
                'order_refunded'    => (int)   ($row->order_refunded    ?? 0),
                'order_failed'      => (int)   ($row->order_failed      ?? 0),
                'total_revenue'     => (float) ($row->revenue           ?? 0),
                'total_charge'      => (float) ($row->revenue           ?? 0),
                'total_cost'        => (float) ($row->cost              ?? 0),
                'total_profit'      => (float) ($row->profit            ?? 0),
                'total_refund'      => (float) ($d['refund_amount']     ?? 0),
                'new_customers'     => $d['new_customers'],
                'total_deposits'    => (int)   ($dep->total_deposits    ?? 0),
                'deposit_amount'    => (float) ($dep->deposit_amount    ?? 0),
            ]
        );
    }

    private function buildStats(string $period, array $todayData): array
    {
        if ($period === 'today') {
            $row     = $todayData['order_row'];
            $deposit = (float) ($todayData['deposit_row']->deposit_amount ?? 0);

            return [
                'orders' => [
                    'total'     => (int) ($row->total           ?? 0),
                    'completed' => (int) ($row->order_completed ?? 0),
                    'running'   => (int) ($row->running         ?? 0),
                    'partial'   => (int) ($row->order_partial   ?? 0),
                    'canceled'  => (int) ($row->canceled_total  ?? 0),
                ],
                'financial' => [
                    'total_revenue' => (float) ($row->revenue ?? 0),
                    'total_cost'    => (float) ($row->cost    ?? 0),
                    'total_profit'  => (float) ($row->profit  ?? 0),
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

        $depositAmount = Dongtien::whereIn('type', [Dongtien::TYPE_DEPOSIT, Dongtien::TYPE_ADJUSTMENT])
            ->where('created_at', '>=', now()->subDays($days)->startOfDay())
            ->sum('amount');

        $completed = (int) ($row->completed ?? 0);
        $partial   = (int) ($row->partial   ?? 0);
        $canceled  = (int) ($row->canceled  ?? 0);

        return [
            'orders' => [
                'total'     => $completed + $partial + $canceled,
                'completed' => $completed,
                'partial'   => $partial,
                'canceled'  => $canceled,
            ],
            'financial' => [
                'total_revenue' => (float) ($row->revenue ?? 0),
                'total_cost'    => (float) ($row->cost    ?? 0),
                'total_profit'  => (float) ($row->profit  ?? 0),
                'total_deposit' => (float) $depositAmount,
            ],
        ];
    }

    private function buildFinancial(string $period, array $todayData): array
    {
        $calcChange = fn(float $current, float $previous): ?float =>
            $previous == 0 ? null : round((($current - $previous) / $previous) * 100, 1);

        if ($period === 'today') {
            $row = $todayData['order_row'];
            $cr  = (float) ($row->revenue ?? 0);
            $cc  = (float) ($row->cost    ?? 0);
            $cp  = (float) ($row->profit  ?? 0);

            $yesterday = now()->subDay();
            $prev = ReportOrderDaily::where('date_at', '>=', $yesterday->copy()->startOfDay()->timestamp)
                ->where('date_at', '<=', $yesterday->copy()->endOfDay()->timestamp)
                ->selectRaw('SUM(total_charge) as revenue, SUM(total_cost) as cost, SUM(total_profit) as profit')
                ->first();

            $pr = (float) ($prev->revenue ?? 0);
            $pc = (float) ($prev->cost    ?? 0);
            $pp = (float) ($prev->profit  ?? 0);
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

            $cr = (float) ($currentRow->revenue  ?? 0);
            $cc = (float) ($currentRow->cost     ?? 0);
            $cp = (float) ($currentRow->profit   ?? 0);
            $pr = (float) ($previousRow->revenue ?? 0);
            $pc = (float) ($previousRow->cost    ?? 0);
            $pp = (float) ($previousRow->profit  ?? 0);
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
                    'revenue' => (float) ($r->revenue ?? 0),
                    'cost'    => (float) ($r->cost    ?? 0),
                    'profit'  => (float) ($r->profit  ?? 0),
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
                'revenue' => (float) ($r->revenue ?? 0),
                'cost'    => (float) ($r->cost    ?? 0),
                'profit'  => (float) ($r->profit  ?? 0),
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
                    'completed' => (int) ($r->completed ?? 0),
                    'running'   => (int) ($r->running   ?? 0),
                    'partial'   => (int) ($r->partial   ?? 0),
                    'canceled'  => (int) ($r->canceled  ?? 0),
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
                'completed' => (int) ($r->completed ?? 0),
                'partial'   => (int) ($r->partial   ?? 0),
                'canceled'  => (int) ($r->canceled  ?? 0),
            ])->values()->all();
    }
}
