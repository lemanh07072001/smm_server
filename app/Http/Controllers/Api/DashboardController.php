<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Dongtien;
use App\Models\LoginHistory;
use App\Models\Order;
use App\Models\ReportDashboardDaily;
use App\Models\ReportOrderDaily;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class DashboardController extends Controller
{
    private const CACHE_TTL = 360; // 6 phút

    /**
     * Đọc từ Redis trước, nếu miss thì đọc từ DB, cache lại và trả về.
     */
    private function getCached(string $key, callable $fallback): mixed
    {
        $data = Cache::get($key);
        if ($data !== null) {
            return $data;
        }

        $data = $fallback();
        Cache::put($key, $data, self::CACHE_TTL);
        return $data;
    }

    /**
     * Lấy thống kê dashboard theo ngày
     */
    public function index(Request $request): JsonResponse
    {
        // Mặc định lấy 30 ngày gần nhất
        $days = $request->get('days', 30);
        $days = min($days, 365); // Giới hạn tối đa 365 ngày

        $reports = ReportDashboardDaily::orderBy('date_at', 'desc')
            ->limit($days)
            ->get();

        return response()->json([
            'data' => $reports,
        ]);
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
                'data' => null,
                'message' => 'Chưa có dữ liệu thống kê cho hôm nay',
            ]);
        }

        return response()->json([
            'data' => $report,
        ]);
    }

    /**
     * Lấy tổng hợp thống kê trong khoảng thời gian
     */
    public function summary(Request $request): JsonResponse
    {
        $fromDate = $request->get('from_date'); // YYYYMMDD
        $toDate = $request->get('to_date');     // YYYYMMDD

        // Mặc định lấy 30 ngày gần nhất
        if (!$fromDate || !$toDate) {
            $toDate = (int) date('Ymd');
            $fromDate = (int) date('Ymd', strtotime('-30 days'));
        }

        $summary = ReportDashboardDaily::where('date_at', '>=', $fromDate)
            ->where('date_at', '<=', $toDate)
            ->selectRaw('
                SUM(total_orders) as total_orders,
                SUM(order_pending) as order_pending,
                SUM(order_processing) as order_processing,
                SUM(order_in_progress) as order_in_progress,
                SUM(order_completed) as order_completed,
                SUM(order_partial) as order_partial,
                SUM(order_canceled) as order_canceled,
                SUM(order_refunded) as order_refunded,
                SUM(order_failed) as order_failed,
                SUM(total_revenue) as total_revenue,
                SUM(total_charge) as total_charge,
                SUM(total_cost) as total_cost,
                SUM(total_profit) as total_profit,
                SUM(total_refund) as total_refund,
                SUM(new_customers) as new_customers,
                SUM(total_deposits) as total_deposits,
                SUM(deposit_amount) as deposit_amount
            ')
            ->first();

        return response()->json([
            'data' => $summary,
            'from_date' => $fromDate,
            'to_date' => $toDate,
        ]);
    }

    /**
     * Lấy thống kê đơn hàng của user đang đăng nhập từ report_order_daily.
     */
    public function userStats(Request $request): JsonResponse
    {
        $user = $request->user();
        $fromDate = $request->input('from_date');
        $toDate = $request->input('to_date');

        $query = ReportOrderDaily::where('user_id', $user->id);

        if ($fromDate) {
            $query->where('date_at', '>=', (int) $fromDate);
        }

        if ($toDate) {
            $query->where('date_at', '<=', (int) $toDate);
        }

        $stats = $query->selectRaw('
            SUM(order_pending) as total_pending,
            SUM(order_processing) as total_processing,
            SUM(order_in_progress) as total_in_progress,
            SUM(order_completed) as total_completed,
            SUM(order_partial) as total_partial,
            SUM(order_canceled) as total_canceled,
            SUM(order_refunded) as total_refunded,
            SUM(order_failed) as total_failed,
            SUM(order_pending + order_processing + order_in_progress + order_completed + order_partial + order_canceled + order_refunded + order_failed) as total_orders,
            SUM(total_quantity) as total_quantity
        ')->first();

        $totalDeposit = Dongtien::where('user_id', $user->id)
            ->where('type', Dongtien::TYPE_DEPOSIT)
            ->sum('amount');

        return response()->json([
            'user_id' => $user->id,
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'data' => [
                'total_orders' => (int) ($stats->total_orders ?? 0),
                'total_quantity' => (int) ($stats->total_quantity ?? 0),
                'total_deposit' => (float) $totalDeposit,
                'status_counts' => [
                    'pending' => (int) ($stats->total_pending ?? 0),
                    'processing' => (int) ($stats->total_processing ?? 0),
                    'in_progress' => (int) ($stats->total_in_progress ?? 0),
                    'completed' => (int) ($stats->total_completed ?? 0),
                    'partial' => (int) ($stats->total_partial ?? 0),
                    'canceled' => (int) ($stats->total_canceled ?? 0),
                    'refunded' => (int) ($stats->total_refunded ?? 0),
                    'failed' => (int) ($stats->total_failed ?? 0),
                ],
            ],
        ]);
    }

    /**
     * Lấy lịch sử đăng nhập có phân trang
     */
    public function recentLogins(Request $request): JsonResponse
    {
        $perPage = $request->input('per_page', 5);
        $user = $request->user();

        $logins = LoginHistory::with('user:id,name,email')
            ->orderBy('login_at', 'desc')
            ->where('user_id', $user->id)
            ->paginate($perPage);

        return response()->json([
            'data' => $logins->items(),
            'pagination' => [
                'current_page' => $logins->currentPage(),
                'last_page' => $logins->lastPage(),
                'per_page' => $logins->perPage(),
                'total' => $logins->total(),
            ],
        ]);
    }

    /**
     * Lấy các loại dịch vụ user đã mua
     */
    public function userPurchasedServices(Request $request): JsonResponse
    {
        $user = $request->user();
        $fromDate = $request->input('from_date'); // YYYYMMDD
        $toDate = $request->input('to_date');     // YYYYMMDD
        $perPage = $request->input('per_page', 5);

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
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'data' => $services->items(),
            'pagination' => [
                'current_page' => $services->currentPage(),
                'last_page' => $services->lastPage(),
                'per_page' => $services->perPage(),
                'total' => $services->total(),
            ],
        ]);
    }

    /**
     * Lấy danh sách danh mục user đã mua theo tháng/năm có phân trang
     */
    public function userPurchasedCategories(Request $request): JsonResponse
    {
        $user = $request->user();
        $month = $request->input('month', date('m'));
        $year = $request->input('year', date('Y'));
        $perPage = $request->input('per_page', 5);

        // Tính from_date và to_date từ month/year (format YYYYMMDD)
        $fromDate = (int) ($year . str_pad($month, 2, '0', STR_PAD_LEFT) . '01');
        $lastDay = date('t', strtotime("$year-$month-01"));
        $toDate = (int) ($year . str_pad($month, 2, '0', STR_PAD_LEFT) . $lastDay);

        // Lấy danh sách service_id user đã mua trong khoảng thời gian
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
            'month' => $month,
            'year' => $year,
            'data' => $serviceStats->items(),
            'pagination' => [
                'current_page' => $serviceStats->currentPage(),
                'last_page' => $serviceStats->lastPage(),
                'per_page' => $serviceStats->perPage(),
                'total' => $serviceStats->total(),
            ],
        ]);
    }

    /**
     * Lấy tổng hợp thống kê toàn hệ thống (đọc từ Redis → DB → query trực tiếp)
     */
    public function totalStats(Request $request): JsonResponse
    {
        $period = $request->get('period', 'today');

        $data = $this->getCached("dashboard:stats:{$period}", fn() => $this->queryStats($period));

        return response()->json(['data' => $data]);
    }

    /**
     * Thống kê tài chính: doanh thu, chi phí, lợi nhuận + % thay đổi so kỳ trước
     */
    public function financialStats(Request $request): JsonResponse
    {
        $period = $request->get('period', 'today');

        $data = $this->getCached("dashboard:financial:{$period}", fn() => $this->queryFinancial($period));

        return response()->json(['period' => $period, 'data' => $data]);
    }

    /**
     * Dữ liệu biểu đồ doanh thu
     */
    public function revenueChart(Request $request): JsonResponse
    {
        $period = $request->get('period', 'today');

        $data = $this->getCached("dashboard:chart:revenue:{$period}", fn() => $this->queryRevenueChart($period));

        return response()->json(['period' => $period, 'data' => $data]);
    }

    /**
     * Dữ liệu biểu đồ đơn hàng theo status
     */
    public function ordersChart(Request $request): JsonResponse
    {
        $period = $request->get('period', 'today');

        $data = $this->getCached("dashboard:chart:orders:{$period}", fn() => $this->queryOrdersChart($period));

        return response()->json(['period' => $period, 'data' => $data]);
    }

    /**
     * Thống kê đơn hàng theo status
     */
    public function orderStats(Request $request): JsonResponse
    {
        $period = $request->get('period', 'today');

        $stats = $this->getCached("dashboard:stats:{$period}", fn() => $this->queryStats($period));

        return response()->json(['period' => $period, 'data' => $stats['orders'] ?? []]);
    }

    // ─── Fallback query methods ────────────────────────────────────────────────

    private function queryStats(string $period): array
    {
        if ($period === 'today') {
            $row = Order::where('created_at', '>=', now()->startOfDay())
                ->selectRaw("
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                    SUM(CASE WHEN status IN ('in_progress','processing') THEN 1 ELSE 0 END) as running,
                    SUM(CASE WHEN status = 'partial' THEN 1 ELSE 0 END) as partial,
                    SUM(CASE WHEN status IN ('canceled','failed') THEN 1 ELSE 0 END) as canceled,
                    SUM(charge_amount) as revenue,
                    SUM(cost_amount) as cost,
                    SUM(profit_amount) as profit
                ")->first();

            $deposit = (float) Dongtien::where('type', Dongtien::TYPE_DEPOSIT)
                ->where('created_at', '>=', now()->startOfDay())
                ->sum('amount');
        } else {
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
                    SUM(deposit_amount) as deposit_total
                ")->first();

            $deposit = (float) ($row->deposit_total ?? 0);
        }

        return [
            'orders' => [
                'total'     => (int) ($row->total ?? 0),
                'completed' => (int) ($row->completed ?? 0),
                'running'   => (int) ($row->running ?? 0),
                'partial'   => (int) ($row->partial ?? 0),
                'canceled'  => (int) ($row->canceled ?? 0),
            ],
            'financial' => [
                'total_revenue' => (float) ($row->revenue ?? 0),
                'total_cost'    => (float) ($row->cost ?? 0),
                'total_profit'  => (float) ($row->profit ?? 0),
                'total_deposit' => $deposit,
            ],
        ];
    }

    private function queryFinancial(string $period): array
    {
        $calcChange = function (float $current, float $previous): ?float {
            if ($previous == 0) return null;
            return round((($current - $previous) / $previous) * 100, 1);
        };

        if ($period === 'today') {
            $current = Order::where('created_at', '>=', now()->startOfDay())
                ->selectRaw('SUM(charge_amount) as revenue, SUM(cost_amount) as cost, SUM(profit_amount) as profit')
                ->first();

            $previous = Order::where('created_at', '>=', now()->subDay()->startOfDay())
                ->where('created_at', '<', now()->startOfDay())
                ->selectRaw('SUM(charge_amount) as revenue, SUM(cost_amount) as cost, SUM(profit_amount) as profit')
                ->first();
        } else {
            $days = $period === '7days' ? 7 : 30;

            $current = ReportDashboardDaily::where('date_at', '>=', (int) now()->subDays($days)->format('Ymd'))
                ->where('date_at', '<=', (int) now()->format('Ymd'))
                ->selectRaw('SUM(total_revenue) as revenue, SUM(total_cost) as cost, SUM(total_profit) as profit')
                ->first();

            $previous = ReportDashboardDaily::where('date_at', '>=', (int) now()->subDays($days * 2)->format('Ymd'))
                ->where('date_at', '<=', (int) now()->subDays($days + 1)->format('Ymd'))
                ->selectRaw('SUM(total_revenue) as revenue, SUM(total_cost) as cost, SUM(total_profit) as profit')
                ->first();
        }

        $cr = (float) ($current->revenue ?? 0);
        $cc = (float) ($current->cost ?? 0);
        $cp = (float) ($current->profit ?? 0);
        $pr = (float) ($previous->revenue ?? 0);
        $pc = (float) ($previous->cost ?? 0);
        $pp = (float) ($previous->profit ?? 0);

        return [
            'revenue'        => $cr,
            'cost'           => $cc,
            'profit'         => $cp,
            'revenue_change' => $calcChange($cr, $pr),
            'cost_change'    => $calcChange($cc, $pc),
            'profit_change'  => $calcChange($cp, $pp),
        ];
    }

    private function queryRevenueChart(string $period): array
    {
        if ($period === 'today') {
            return Order::where('created_at', '>=', now()->startOfDay())
                ->selectRaw("DATE_FORMAT(created_at, '%H:00') as date, SUM(charge_amount) as revenue, SUM(cost_amount) as cost, SUM(profit_amount) as profit")
                ->groupBy('date')->orderBy('date')->get()
                ->map(fn($r) => ['date' => $r->date, 'revenue' => (float)($r->revenue??0), 'cost' => (float)($r->cost??0), 'profit' => (float)($r->profit??0)])
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

    private function queryOrdersChart(string $period): array
    {
        if ($period === 'today') {
            return Order::where('created_at', '>=', now()->startOfDay())
                ->selectRaw("
                    DATE_FORMAT(created_at, '%H:00') as date,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                    SUM(CASE WHEN status IN ('in_progress','processing') THEN 1 ELSE 0 END) as running,
                    SUM(CASE WHEN status = 'partial' THEN 1 ELSE 0 END) as partial,
                    SUM(CASE WHEN status IN ('canceled','failed') THEN 1 ELSE 0 END) as canceled
                ")
                ->groupBy('date')->orderBy('date')->get()
                ->map(fn($r) => ['date' => $r->date, 'completed' => (int)($r->completed??0), 'running' => (int)($r->running??0), 'partial' => (int)($r->partial??0), 'canceled' => (int)($r->canceled??0)])
                ->values()->all();
        }

        $days = $period === '7days' ? 7 : 30;

        return ReportDashboardDaily::where('date_at', '>=', (int) now()->subDays($days)->format('Ymd'))
            ->where('date_at', '<=', (int) now()->format('Ymd'))
            ->orderBy('date_at')
            ->get(['date_at', 'order_completed', 'order_in_progress', 'order_processing', 'order_partial', 'order_canceled', 'order_failed'])
            ->map(fn($r) => [
                'date'      => $this->formatDateAt($r->date_at),
                'completed' => (int)($r->order_completed    ?? 0),
                'running'   => (int)(($r->order_in_progress ?? 0) + ($r->order_processing ?? 0)),
                'partial'   => (int)($r->order_partial      ?? 0),
                'canceled'  => (int)(($r->order_canceled    ?? 0) + ($r->order_failed ?? 0)),
            ])->values()->all();
    }

    private function formatDateAt(int $dateAt): string
    {
        $s = (string) $dateAt;
        return substr($s, 0, 4) . '-' . substr($s, 4, 2) . '-' . substr($s, 6, 2);
    }
}
