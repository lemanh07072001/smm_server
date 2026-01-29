<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Dongtien;
use App\Models\LoginHistory;
use App\Models\ReportDashboardDaily;
use App\Models\ReportOrderDaily;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
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
     * Lấy tổng hợp thống kê toàn hệ thống
     * - Tổng đơn hàng theo status
     * - Tổng doanh thu, cost, tiền nạp
     */
    public function totalStats(): JsonResponse
    {
        $orderStats = \App\Models\Order::selectRaw('
            COUNT(*) as total_orders,
            SUM(CASE WHEN status = "completed" THEN 1 ELSE 0 END) as completed_orders,
            SUM(CASE WHEN status IN ("in_progress", "processing") THEN 1 ELSE 0 END) as running_orders,
            SUM(CASE WHEN status = "partial" THEN 1 ELSE 0 END) as partial_orders,
            SUM(CASE WHEN status IN ("canceled", "failed") THEN 1 ELSE 0 END) as canceled_orders,
            SUM(charge_amount) as total_revenue,
            SUM(cost_amount) as total_cost,
            SUM(profit_amount) as total_profit
        ')->first();

        $depositTotal = Dongtien::where('type', Dongtien::TYPE_DEPOSIT)
            ->sum('amount');

        return response()->json([
            'data' => [
                'orders' => [
                    'total' => $orderStats->total_orders ?? 0,
                    'completed' => $orderStats->completed_orders ?? 0,
                    'running' => $orderStats->running_orders ?? 0,
                    'partial' => $orderStats->partial_orders ?? 0,
                    'canceled' => $orderStats->canceled_orders ?? 0,
                ],
                'financial' => [
                    'total_revenue' => (float) ($orderStats->total_revenue ?? 0),
                    'total_cost' => (float) ($orderStats->total_cost ?? 0),
                    'total_profit' => (float) ($orderStats->total_profit ?? 0),
                    'total_deposit' => (float) $depositTotal,
                ],
            ],
        ]);
    }

    /**
     * API 1: Thống kê chi phí, doanh thu, lợi nhuận
     * Params: period (today|7days|30days)
     */
    public function financialStats(Request $request): JsonResponse
    {
        $period = $request->get('period', 'today');

        $query = \App\Models\Order::query();

        // Filter theo thời gian
        switch ($period) {
            case 'today':
                $query->whereDate('created_at', today());
                break;
            case '7days':
                $query->where('created_at', '>=', now()->subDays(7));
                break;
            case '30days':
                $query->where('created_at', '>=', now()->subDays(30));
                break;
        }

        $stats = $query->selectRaw('
            SUM(charge_amount) as total_revenue,
            SUM(cost_amount) as total_cost,
            SUM(profit_amount) as total_profit
        ')->first();

        return response()->json([
            'period' => $period,
            'data' => [
                'revenue' => (float) ($stats->total_revenue ?? 0),
                'cost' => (float) ($stats->total_cost ?? 0),
                'profit' => (float) ($stats->total_profit ?? 0),
            ],
        ]);
    }

    /**
     * API 2: Thống kê đơn hàng theo status
     * Params: period (today|7days|30days)
     */
    public function orderStats(Request $request): JsonResponse
    {
        $period = $request->get('period', 'today');

        $query = \App\Models\Order::query();

        // Filter theo thời gian
        switch ($period) {
            case 'today':
                $query->whereDate('created_at', today());
                break;
            case '7days':
                $query->where('created_at', '>=', now()->subDays(7));
                break;
            case '30days':
                $query->where('created_at', '>=', now()->subDays(30));
                break;
        }

        $stats = $query->selectRaw('
            SUM(CASE WHEN status = "completed" THEN 1 ELSE 0 END) as completed,
            SUM(CASE WHEN status = "partial" THEN 1 ELSE 0 END) as partial,
            SUM(CASE WHEN status = "canceled" THEN 1 ELSE 0 END) as canceled,
            SUM(CASE WHEN status = "failed" THEN 1 ELSE 0 END) as failed
        ')->first();

        return response()->json([
            'period' => $period,
            'data' => [
                'completed' => (int) ($stats->completed ?? 0),
                'partial' => (int) ($stats->partial ?? 0),
                'canceled' => (int) ($stats->canceled ?? 0),
                'failed' => (int) ($stats->failed ?? 0),
            ],
        ]);
    }
}
