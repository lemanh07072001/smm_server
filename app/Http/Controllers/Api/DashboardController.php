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
     * Lấy 10 lịch sử đăng nhập mới nhất
     */
    public function recentLogins(Request $request): JsonResponse
    {
        $limit = $request->get('limit', 10);
        $limit = min($limit, 50);
        $user = $request->user();

        $logins = LoginHistory::with('user:id,name,email')
            ->orderBy('login_at', 'desc')
            ->where('user_id',$user->id)
            ->limit($limit)
            ->get();

        return response()->json([
            'data' => $logins,
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
            ->get();

        return response()->json([
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'data' => $services,
        ]);
    }

    /**
     * Lấy danh sách danh mục user đã mua theo tháng/năm
     */
    public function userPurchasedCategories(Request $request): JsonResponse
    {
        $user = $request->user();
        $month = $request->input('month', date('m'));
        $year = $request->input('year', date('Y'));

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
            ->get();

        return response()->json([
            'month' => $month,
            'year' => $year,
            'data' => $serviceStats,
        ]);
    }
}
