<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ReportDashboardDaily;
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
}
