<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Dongtien;
use App\Models\LoginHistory;
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
     * Tổng hợp thống kê toàn hệ thống — đọc từ Redis
     */
    public function totalStats(Request $request): JsonResponse
    {
        $period = $request->get('period', 'today');
        $data   = $this->getCached("dashboard:stats:{$period}");

        return response()->json(['data' => $data]);
    }

    /**
     * Thống kê tài chính: doanh thu, chi phí, lợi nhuận + % thay đổi so kỳ trước
     */
    public function financialStats(Request $request): JsonResponse
    {
        $period = $request->get('period', 'today');
        $data   = $this->getCached("dashboard:financial:{$period}");

        return response()->json(['period' => $period, 'data' => $data]);
    }

    /**
     * Dữ liệu biểu đồ doanh thu
     */
    public function revenueChart(Request $request): JsonResponse
    {
        $period = $request->get('period', 'today');
        $data   = $this->getCached("dashboard:chart:revenue:{$period}");

        return response()->json(['period' => $period, 'data' => $data]);
    }

    /**
     * Dữ liệu biểu đồ đơn hàng theo status
     */
    public function ordersChart(Request $request): JsonResponse
    {
        $period = $request->get('period', 'today');
        $data   = $this->getCached("dashboard:chart:orders:{$period}");

        return response()->json(['period' => $period, 'data' => $data]);
    }

    /**
     * Thống kê đơn hàng theo status
     */
    public function orderStats(Request $request): JsonResponse
    {
        $period = $request->get('period', 'today');
        $stats  = $this->getCached("dashboard:stats:{$period}");

        return response()->json(['period' => $period, 'data' => $stats['orders'] ?? []]);
    }
}
