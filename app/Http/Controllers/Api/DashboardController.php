<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AffiliateCommission;
use App\Models\Dongtien;
use App\Models\LoginHistory;
use App\Models\Order;
use App\Models\ReportDashboardDaily;
use App\Models\ReportOrderDaily;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

class DashboardController extends Controller
{
    /**
     * Đọc từ Redis, nếu miss thì chạy dashboard:refresh để populate rồi đọc lại.
     */
    private function getCached(string $key): mixed
    {
        $data = Cache::get($key);

        if ($data !== null) {
            return $data;
        }

        Artisan::call('report:refresh');

        return Cache::get($key);
    }

    /**
     * Lấy thống kê dashboard theo ngày
     */
    public function index(Request $request): JsonResponse
    {
        $days = min($request->get('days', 30), 365);

        $reports = ReportDashboardDaily::orderBy('date_at', 'desc')
            ->limit($days)
            ->get();

        return response()->json(['data' => $reports]);
    }

    /**
     * Lấy thống kê dashboard hôm nay
     */
    public function today(): JsonResponse
    {
        $dateAt = (int) date('Ymd');
        $report = ReportDashboardDaily::where('date_at', $dateAt)->first();

        if (!$report) {
            return response()->json([
                'data'    => null,
                'message' => 'Chưa có dữ liệu thống kê cho hôm nay',
            ]);
        }

        return response()->json(['data' => $report]);
    }

    /**
     * Lấy tổng hợp thống kê trong khoảng thời gian
     */
    public function summary(Request $request): JsonResponse
    {
        $toDate   = $request->get('to_date',   (int) date('Ymd'));
        $fromDate = $request->get('from_date',  (int) date('Ymd', strtotime('-30 days')));

        $summary = ReportDashboardDaily::where('date_at', '>=', $fromDate)
            ->where('date_at', '<=', $toDate)
            ->selectRaw('
                SUM(total_orders)      as total_orders,
                SUM(order_pending)     as order_pending,
                SUM(order_processing)  as order_processing,
                SUM(order_in_progress) as order_in_progress,
                SUM(order_completed)   as order_completed,
                SUM(order_partial)     as order_partial,
                SUM(order_canceled)    as order_canceled,
                SUM(order_refunded)    as order_refunded,
                SUM(order_failed)      as order_failed,
                SUM(total_revenue)     as total_revenue,
                SUM(total_charge)      as total_charge,
                SUM(total_cost)        as total_cost,
                SUM(total_profit)      as total_profit,
                SUM(total_refund)      as total_refund,
                SUM(new_customers)     as new_customers,
                SUM(total_deposits)    as total_deposits,
                SUM(deposit_amount)    as deposit_amount
            ')
            ->first();

        return response()->json([
            'data'      => $summary,
            'from_date' => $fromDate,
            'to_date'   => $toDate,
        ]);
    }

    /**
     * Lấy thống kê đơn hàng của user đang đăng nhập
     */
    public function userStats(Request $request): JsonResponse
    {
        $user     = $request->user();
        $fromDate = $request->input('from_date');
        $toDate   = $request->input('to_date');

        $query = ReportOrderDaily::where('user_id', $user->id);

        if ($fromDate) {
            $query->where('date_at', '>=', (int) $fromDate);
        }

        if ($toDate) {
            $query->where('date_at', '<=', (int) $toDate);
        }

        $stats = $query->selectRaw('
            SUM(order_pending)     as total_pending,
            SUM(order_processing)  as total_processing,
            SUM(order_in_progress) as total_in_progress,
            SUM(order_completed)   as total_completed,
            SUM(order_partial)     as total_partial,
            SUM(order_canceled)    as total_canceled,
            SUM(order_refunded)    as total_refunded,
            SUM(order_failed)      as total_failed,
            SUM(order_pending + order_processing + order_in_progress + order_completed + order_partial + order_canceled + order_refunded + order_failed) as total_orders,
            SUM(total_quantity)    as total_quantity
        ')->first();

        $totalDeposit = Dongtien::where('user_id', $user->id)
            ->where('type', Dongtien::TYPE_DEPOSIT)
            ->sum('amount');

        return response()->json([
            'user_id'   => $user->id,
            'from_date' => $fromDate,
            'to_date'   => $toDate,
            'data'      => [
                'total_orders'   => (int)   ($stats->total_orders   ?? 0),
                'total_quantity' => (int)   ($stats->total_quantity  ?? 0),
                'total_deposit'  => (float) $totalDeposit,
                'status_counts'  => [
                    'pending'     => (int) ($stats->total_pending     ?? 0),
                    'processing'  => (int) ($stats->total_processing  ?? 0),
                    'in_progress' => (int) ($stats->total_in_progress ?? 0),
                    'completed'   => (int) ($stats->total_completed   ?? 0),
                    'partial'     => (int) ($stats->total_partial     ?? 0),
                    'canceled'    => (int) ($stats->total_canceled    ?? 0),
                    'refunded'    => (int) ($stats->total_refunded    ?? 0),
                    'failed'      => (int) ($stats->total_failed      ?? 0),
                ],
            ],
        ]);
    }

    /**
     * Lấy lịch sử đăng nhập có phân trang
     */
    public function recentLogins(Request $request): JsonResponse
    {
        $user    = $request->user();
        $perPage = $request->input('per_page', 5);

        $logins = LoginHistory::with('user:id,name,email')
            ->where('user_id', $user->id)
            ->orderBy('login_at', 'desc')
            ->paginate($perPage);

        return response()->json([
            'data'       => $logins->items(),
            'pagination' => [
                'current_page' => $logins->currentPage(),
                'last_page'    => $logins->lastPage(),
                'per_page'     => $logins->perPage(),
                'total'        => $logins->total(),
            ],
        ]);
    }

    /**
     * Lấy các loại dịch vụ user đã mua
     */
    public function userPurchasedServices(Request $request): JsonResponse
    {
        $user     = $request->user();
        $fromDate = $request->input('from_date');
        $toDate   = $request->input('to_date');
        $perPage  = $request->input('per_page', 5);

        $query = ReportOrderDaily::where('user_id', $user->id);

        if ($fromDate) {
            $query->where('date_at', '>=', (int) $fromDate);
        }

        if ($toDate) {
            $query->where('date_at', '<=', (int) $toDate);
        }

        $services = $query->select('service_id')
            ->selectRaw('SUM(order_pending + order_processing + order_in_progress + order_completed + order_partial + order_canceled + order_refunded + order_failed) as total_orders')
            ->selectRaw('SUM(total_quantity) as total_quantity')
            ->selectRaw('SUM(total_charge) as total_spent')
            ->groupBy('service_id')
            ->with('service:id,name,category_group_id,group_id,sell_rate')
            ->orderByDesc('total_orders')
            ->paginate($perPage);

        return response()->json([
            'from_date'  => $fromDate,
            'to_date'    => $toDate,
            'data'       => $services->items(),
            'pagination' => [
                'current_page' => $services->currentPage(),
                'last_page'    => $services->lastPage(),
                'per_page'     => $services->perPage(),
                'total'        => $services->total(),
            ],
        ]);
    }

    /**
     * Lấy danh sách dịch vụ user đã mua theo tháng/năm
     */
    public function userPurchasedCategories(Request $request): JsonResponse
    {
        $user    = $request->user();
        $month   = $request->input('month', date('m'));
        $year    = $request->input('year',  date('Y'));
        $perPage = $request->input('per_page', 5);

        $fromDate = (int) ($year . str_pad($month, 2, '0', STR_PAD_LEFT) . '01');
        $lastDay  = date('t', strtotime("$year-$month-01"));
        $toDate   = (int) ($year . str_pad($month, 2, '0', STR_PAD_LEFT) . $lastDay);

        $serviceStats = ReportOrderDaily::where('user_id', $user->id)
            ->where('date_at', '>=', $fromDate)
            ->where('date_at', '<=', $toDate)
            ->join('services', 'report_order_daily.service_id', '=', 'services.id')
            ->select('services.name')
            ->selectRaw('SUM(order_pending + order_processing + order_in_progress + order_completed + order_partial + order_canceled + order_refunded + order_failed) as total_orders')
            ->selectRaw('SUM(total_quantity) as total_quantity')
            ->selectRaw('SUM(total_charge) as total_spent')
            ->groupBy('services.name')
            ->orderByDesc('total_orders')
            ->paginate($perPage);

        return response()->json([
            'month'      => $month,
            'year'       => $year,
            'data'       => $serviceStats->items(),
            'pagination' => [
                'current_page' => $serviceStats->currentPage(),
                'last_page'    => $serviceStats->lastPage(),
                'per_page'     => $serviceStats->perPage(),
                'total'        => $serviceStats->total(),
            ],
        ]);
    }

    /**
     * Tổng hợp thống kê toàn hệ thống — đọc từ Redis (hoặc query all)
     */
    public function totalStats(Request $request): JsonResponse
    {
        $period = $request->get('period', 'today');

        if ($period === 'all') {
            $data = $this->buildAllStats();
            return response()->json(['data' => $data]);
        }

        $data = $this->getCached("dashboard:stats:{$period}");
        return response()->json(['data' => $data]);
    }

    /**
     * Thống kê tài chính: doanh thu, chi phí, lợi nhuận + % thay đổi so kỳ trước
     */
    public function financialStats(Request $request): JsonResponse
    {
        $period = $request->get('period', 'today');

        if ($period === 'all') {
            $data = $this->buildAllFinancial();
            return response()->json(['period' => $period, 'data' => $data]);
        }

        $data = $this->getCached("dashboard:financial:{$period}");
        return response()->json(['period' => $period, 'data' => $data]);
    }

    /**
     * Dữ liệu biểu đồ doanh thu
     */
    public function revenueChart(Request $request): JsonResponse
    {
        $period = $request->get('period', 'today');

        if ($period === 'all') {
            $data = $this->buildAllRevenueChart();
            return response()->json(['period' => $period, 'data' => $data]);
        }

        $data = $this->getCached("dashboard:chart:revenue:{$period}");
        return response()->json(['period' => $period, 'data' => $data]);
    }

    /**
     * Dữ liệu biểu đồ đơn hàng theo status
     */
    public function ordersChart(Request $request): JsonResponse
    {
        $period = $request->get('period', 'today');

        if ($period === 'all') {
            $data = $this->buildAllOrdersChart();
            return response()->json(['period' => $period, 'data' => $data]);
        }

        $data = $this->getCached("dashboard:chart:orders:{$period}");
        return response()->json(['period' => $period, 'data' => $data]);
    }

    /**
     * Thống kê đơn hàng theo status
     */
    public function orderStats(Request $request): JsonResponse
    {
        $period = $request->get('period', 'today');

        if ($period === 'all') {
            $stats = $this->buildAllStats();
            return response()->json(['period' => $period, 'data' => $stats['orders'] ?? []]);
        }

        $stats = $this->getCached("dashboard:stats:{$period}");
        return response()->json(['period' => $period, 'data' => $stats['orders'] ?? []]);
    }

    private function buildAllStats(): array
    {
        $row = ReportOrderDaily::selectRaw("
            SUM(order_completed) as completed,
            SUM(order_partial)   as partial,
            SUM(order_canceled + order_failed) as canceled,
            SUM(order_completed + order_partial + order_canceled + order_failed) as total,
            SUM(total_charge)  as revenue,
            SUM(total_cost)    as cost,
            SUM(total_profit)  as profit
        ")->first();

        $depositAmount = \App\Models\Dongtien::where('type', \App\Models\Dongtien::TYPE_DEPOSIT)->sum('amount');

        return [
            'orders' => [
                'total'     => (int)   ($row->total     ?? 0),
                'completed' => (int)   ($row->completed ?? 0),
                'running'   => 0,
                'partial'   => (int)   ($row->partial   ?? 0),
                'canceled'  => (int)   ($row->canceled  ?? 0),
            ],
            'financial' => [
                'total_revenue' => (float) ($row->revenue ?? 0),
                'total_cost'    => (float) ($row->cost    ?? 0),
                'total_profit'  => (float) ($row->profit  ?? 0),
                'total_deposit' => (float) $depositAmount,
            ],
        ];
    }

    private function buildAllFinancial(): array
    {
        $row = ReportOrderDaily::selectRaw("
            SUM(total_charge)  as revenue,
            SUM(total_cost)    as cost,
            SUM(total_profit)  as profit
        ")->first();

        return [
            'revenue'        => (float) ($row->revenue ?? 0),
            'cost'           => (float) ($row->cost    ?? 0),
            'profit'         => (float) ($row->profit  ?? 0),
            'revenue_change' => null,
            'cost_change'    => null,
            'profit_change'  => null,
        ];
    }

    private function buildAllRevenueChart(): array
    {
        return ReportOrderDaily::selectRaw('date_at, SUM(total_charge) as revenue, SUM(total_cost) as cost, SUM(total_profit) as profit')
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

    private function buildAllOrdersChart(): array
    {
        return ReportOrderDaily::selectRaw('date_at, SUM(order_completed) as completed, SUM(order_partial) as partial, SUM(order_canceled + order_failed) as canceled')
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

    // ─── Overview endpoint ────────────────────────────────────────────────────

    public function overview(Request $request): JsonResponse
    {
        $period    = $request->get('period', 'all');
        $fromDate  = $request->get('from_date');
        $toDate    = $request->get('to_date');

        [$fromTs, $toTs, $fromDt, $toDt] = $this->resolveDateRange($period, $fromDate, $toDate);

        // ── Financials từ report_order_daily ──────────────────────────────
        $reportQuery = ReportOrderDaily::query();
        if ($fromTs) $reportQuery->where('date_at', '>=', $fromTs);
        if ($toTs)   $reportQuery->where('date_at', '<=', $toTs);

        $report = $reportQuery->selectRaw("
            SUM(order_completed)                           as completed_count,
            SUM(order_partial)                             as partial_count,
            SUM(order_canceled)                            as canceled_count,
            SUM(order_failed)                              as failed_count,
            SUM(order_pending + order_processing + order_in_progress + order_completed + order_partial + order_canceled + order_failed) as total_count,
            SUM(total_charge)                              as revenue,
            SUM(total_cost)                                as cost,
            SUM(total_profit)                              as profit,
            SUM(total_refund)                              as refunded
        ")->first();

        // ── Pipeline: charge_amount của orders đang chạy ──────────────────
        $pipelineQuery = Order::whereIn('status', ['pending', 'processing', 'in_progress']);
        if ($fromDt) $pipelineQuery->where('created_at', '>=', $fromDt);
        if ($toDt)   $pipelineQuery->where('created_at', '<=', $toDt);
        $pipeline = (float) $pipelineQuery->sum('charge_amount');

        // ── Affiliate commission ──────────────────────────────────────────
        $affQuery = AffiliateCommission::query();
        if ($fromDt) $affQuery->where('created_at', '>=', $fromDt);
        if ($toDt)   $affQuery->where('created_at', '<=', $toDt);
        $affiliateCommission = (float) $affQuery->sum('commission_amount');

        // ── Dongtien (cash flow) ──────────────────────────────────────────
        $cashTypes = [
            'deposit_auto'   => ['deposit'],
            'deposit_manual' => ['adjustment'],
            'payment'        => ['charge'],
            'refund'         => ['refund'],
            'affiliate_withdraw' => ['withdraw'],
        ];

        $cashFlow = [];
        foreach ($cashTypes as $key => $types) {
            $q = Dongtien::whereIn('type', $types);
            if ($fromDt) $q->where('created_at', '>=', $fromDt);
            if ($toDt)   $q->where('created_at', '<=', $toDt);
            $cashFlow[$key] = [
                'amount' => (float) $q->sum('amount'),
                'count'  => (int)   $q->count(),
            ];
        }

        // ── Days in range (cho TB/ngày) ───────────────────────────────────
        $days = 1;
        if ($fromDt && $toDt) {
            $days = max(1, (int) ceil((strtotime($toDt) - strtotime($fromDt)) / 86400));
        } elseif ($period === '7days') {
            $days = 7;
        } elseif ($period === '30days') {
            $days = 30;
        } elseif ($period === 'all') {
            $firstOrder = Order::min('created_at');
            $days = $firstOrder ? max(1, (int) ceil((time() - strtotime($firstOrder)) / 86400)) : 1;
        }

        // Doanh thu thực = tiền user thanh toán (dongtien type=charge)
        $revenue  = $cashFlow['payment']['amount'];
        $cost     = (float) ($report->cost ?? 0);
        // Hoàn tiền user = tiền thực hoàn vào ví (dongtien type=refund)
        $refunded = $cashFlow['refund']['amount'];
        $profit   = $revenue - $cost - $refunded - $affiliateCommission;

        return response()->json([
            'data' => [
                'profit'     => $profit,
                'revenue'    => $revenue,
                'cost'       => $cost,
                'refunded'   => $refunded,
                'affiliate'  => $affiliateCommission,
                'pipeline'   => $pipeline,
                'margin'     => $revenue > 0 ? round($profit / $revenue * 100, 1) : 0,
                'avg_per_day' => round($profit / $days, 0),
                'avg_revenue_per_day' => round($revenue / $days, 0),
                'orders' => [
                    'total'     => (int) ($report->total_count     ?? 0),
                    'completed' => (int) ($report->completed_count ?? 0),
                    'partial'   => (int) ($report->partial_count   ?? 0),
                    'canceled'  => (int) ($report->canceled_count  ?? 0),
                    'failed'    => (int) ($report->failed_count    ?? 0),
                ],
                'deposits' => [
                    'total'  => $cashFlow['deposit_auto']['amount'] + $cashFlow['deposit_manual']['amount'],
                    'count'  => $cashFlow['deposit_auto']['count']  + $cashFlow['deposit_manual']['count'],
                    'auto'   => $cashFlow['deposit_auto'],
                    'manual' => $cashFlow['deposit_manual'],
                ],
                'cash_flow' => $cashFlow,
                'days' => $days,
            ],
        ]);
    }

    private function resolveDateRange(string $period, ?string $fromDate, ?string $toDate): array
    {
        if ($fromDate && $toDate) {
            $fromTs = strtotime($fromDate . ' 00:00:00');
            $toTs   = strtotime($toDate   . ' 23:59:59');
            return [$fromTs, $toTs, $fromDate . ' 00:00:00', $toDate . ' 23:59:59'];
        }

        $now = now();

        return match ($period) {
            'today'   => [
                $now->copy()->startOfDay()->timestamp,
                $now->copy()->endOfDay()->timestamp,
                $now->copy()->startOfDay()->toDateTimeString(),
                $now->copy()->endOfDay()->toDateTimeString(),
            ],
            '7days'   => [
                $now->copy()->subDays(7)->startOfDay()->timestamp,
                $now->copy()->endOfDay()->timestamp,
                $now->copy()->subDays(7)->startOfDay()->toDateTimeString(),
                $now->copy()->endOfDay()->toDateTimeString(),
            ],
            '30days'  => [
                $now->copy()->subDays(30)->startOfDay()->timestamp,
                $now->copy()->endOfDay()->timestamp,
                $now->copy()->subDays(30)->startOfDay()->toDateTimeString(),
                $now->copy()->endOfDay()->toDateTimeString(),
            ],
            default   => [null, null, null, null], // all time
        };
    }
}
