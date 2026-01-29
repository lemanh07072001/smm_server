<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\ReportDashboardDaily;
use Carbon\Carbon;
use Illuminate\Http\Request;

class StatisticsController extends Controller
{
    /**
     * Get revenue and order statistics for yesterday, last 7 days, and last 30 days
     */
    public function summary(Request $request)
    {
        try {
            $today = Carbon::today();
            $yesterday = Carbon::yesterday();

            // Calculate date ranges in YYYYMMDD format
            $yesterdayDate = (int) $yesterday->format('Ymd');
            $last7DaysDate = (int) $today->copy()->subDays(7)->format('Ymd');
            $last30DaysDate = (int) $today->copy()->subDays(30)->format('Ymd');
            $todayDate = (int) $today->format('Ymd');

            // Yesterday statistics
            $yesterdayStats = ReportDashboardDaily::where('date_at', $yesterdayDate)->first();

            // Last 7 days statistics
            $last7DaysStats = ReportDashboardDaily::where('date_at', '>=', $last7DaysDate)
                ->where('date_at', '<', $todayDate)
                ->selectRaw('
                    SUM(total_orders) as total_orders,
                    SUM(order_completed) as order_completed,
                    SUM(order_pending) as order_pending,
                    SUM(order_processing) as order_processing,
                    SUM(order_in_progress) as order_in_progress,
                    SUM(order_partial) as order_partial,
                    SUM(order_canceled) as order_canceled,
                    SUM(order_failed) as order_failed,
                    SUM(total_revenue) as total_revenue,
                    SUM(total_charge) as total_charge,
                    SUM(total_cost) as total_cost,
                    SUM(total_profit) as total_profit,
                    SUM(total_refund) as total_refund
                ')
                ->first();

            // Last 30 days statistics
            $last30DaysStats = ReportDashboardDaily::where('date_at', '>=', $last30DaysDate)
                ->where('date_at', '<', $todayDate)
                ->selectRaw('
                    SUM(total_orders) as total_orders,
                    SUM(order_completed) as order_completed,
                    SUM(order_pending) as order_pending,
                    SUM(order_processing) as order_processing,
                    SUM(order_in_progress) as order_in_progress,
                    SUM(order_partial) as order_partial,
                    SUM(order_canceled) as order_canceled,
                    SUM(order_failed) as order_failed,
                    SUM(total_revenue) as total_revenue,
                    SUM(total_charge) as total_charge,
                    SUM(total_cost) as total_cost,
                    SUM(total_profit) as total_profit,
                    SUM(total_refund) as total_refund
                ')
                ->first();

            return response()->json([
                'data' => [
                    'yesterday' => [
                        'date' => $yesterday->format('Y-m-d'),
                        'total_orders' => $yesterdayStats->total_orders ?? 0,
                        'order_completed' => $yesterdayStats->order_completed ?? 0,
                        'order_pending' => $yesterdayStats->order_pending ?? 0,
                        'order_processing' => $yesterdayStats->order_processing ?? 0,
                        'order_in_progress' => $yesterdayStats->order_in_progress ?? 0,
                        'order_partial' => $yesterdayStats->order_partial ?? 0,
                        'order_canceled' => $yesterdayStats->order_canceled ?? 0,
                        'order_failed' => $yesterdayStats->order_failed ?? 0,
                        'total_revenue' => (float) ($yesterdayStats->total_revenue ?? 0),
                        'total_charge' => (float) ($yesterdayStats->total_charge ?? 0),
                        'total_cost' => (float) ($yesterdayStats->total_cost ?? 0),
                        'total_profit' => (float) ($yesterdayStats->total_profit ?? 0),
                        'total_refund' => (float) ($yesterdayStats->total_refund ?? 0),
                    ],
                    'last_7_days' => [
                        'date_from' => $today->copy()->subDays(7)->format('Y-m-d'),
                        'date_to' => $yesterday->format('Y-m-d'),
                        'total_orders' => $last7DaysStats->total_orders ?? 0,
                        'order_completed' => $last7DaysStats->order_completed ?? 0,
                        'order_pending' => $last7DaysStats->order_pending ?? 0,
                        'order_processing' => $last7DaysStats->order_processing ?? 0,
                        'order_in_progress' => $last7DaysStats->order_in_progress ?? 0,
                        'order_partial' => $last7DaysStats->order_partial ?? 0,
                        'order_canceled' => $last7DaysStats->order_canceled ?? 0,
                        'order_failed' => $last7DaysStats->order_failed ?? 0,
                        'total_revenue' => (float) ($last7DaysStats->total_revenue ?? 0),
                        'total_charge' => (float) ($last7DaysStats->total_charge ?? 0),
                        'total_cost' => (float) ($last7DaysStats->total_cost ?? 0),
                        'total_profit' => (float) ($last7DaysStats->total_profit ?? 0),
                        'total_refund' => (float) ($last7DaysStats->total_refund ?? 0),
                    ],
                    'last_30_days' => [
                        'date_from' => $today->copy()->subDays(30)->format('Y-m-d'),
                        'date_to' => $yesterday->format('Y-m-d'),
                        'total_orders' => $last30DaysStats->total_orders ?? 0,
                        'order_completed' => $last30DaysStats->order_completed ?? 0,
                        'order_pending' => $last30DaysStats->order_pending ?? 0,
                        'order_processing' => $last30DaysStats->order_processing ?? 0,
                        'order_in_progress' => $last30DaysStats->order_in_progress ?? 0,
                        'order_partial' => $last30DaysStats->order_partial ?? 0,
                        'order_canceled' => $last30DaysStats->order_canceled ?? 0,
                        'order_failed' => $last30DaysStats->order_failed ?? 0,
                        'total_revenue' => (float) ($last30DaysStats->total_revenue ?? 0),
                        'total_charge' => (float) ($last30DaysStats->total_charge ?? 0),
                        'total_cost' => (float) ($last30DaysStats->total_cost ?? 0),
                        'total_profit' => (float) ($last30DaysStats->total_profit ?? 0),
                        'total_refund' => (float) ($last30DaysStats->total_refund ?? 0),
                    ],
                ],
                'message' => 'Revenue statistics retrieved successfully'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to retrieve statistics',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get revenue statistics for a custom date range
     */
    public function revenue(Request $request)
    {
        try {
            $fromDate = $request->input('from_date');
            $toDate = $request->input('to_date');

            if (!$fromDate || !$toDate) {
                return response()->json([
                    'message' => 'from_date and to_date are required (format: YYYYMMDD)'
                ], 400);
            }

            $stats = ReportDashboardDaily::where('date_at', '>=', (int) $fromDate)
                ->where('date_at', '<=', (int) $toDate)
                ->selectRaw('
                    SUM(total_orders) as total_orders,
                    SUM(order_completed) as order_completed,
                    SUM(order_pending) as order_pending,
                    SUM(order_processing) as order_processing,
                    SUM(order_in_progress) as order_in_progress,
                    SUM(order_partial) as order_partial,
                    SUM(order_canceled) as order_canceled,
                    SUM(order_failed) as order_failed,
                    SUM(total_revenue) as total_revenue,
                    SUM(total_charge) as total_charge,
                    SUM(total_cost) as total_cost,
                    SUM(total_profit) as total_profit,
                    SUM(total_refund) as total_refund
                ')
                ->first();

            return response()->json([
                'data' => [
                    'date_from' => Carbon::createFromFormat('Ymd', $fromDate)->format('Y-m-d'),
                    'date_to' => Carbon::createFromFormat('Ymd', $toDate)->format('Y-m-d'),
                    'total_orders' => $stats->total_orders ?? 0,
                    'order_completed' => $stats->order_completed ?? 0,
                    'order_pending' => $stats->order_pending ?? 0,
                    'order_processing' => $stats->order_processing ?? 0,
                    'order_in_progress' => $stats->order_in_progress ?? 0,
                    'order_partial' => $stats->order_partial ?? 0,
                    'order_canceled' => $stats->order_canceled ?? 0,
                    'order_failed' => $stats->order_failed ?? 0,
                    'total_revenue' => (float) ($stats->total_revenue ?? 0),
                    'total_charge' => (float) ($stats->total_charge ?? 0),
                    'total_cost' => (float) ($stats->total_cost ?? 0),
                    'total_profit' => (float) ($stats->total_profit ?? 0),
                    'total_refund' => (float) ($stats->total_refund ?? 0),
                ],
                'message' => 'Revenue statistics retrieved successfully'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to retrieve revenue statistics',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get daily revenue breakdown for a date range
     */
    public function dailyBreakdown(Request $request)
    {
        try {
            $fromDate = $request->input('from_date');
            $toDate = $request->input('to_date');

            if (!$fromDate || !$toDate) {
                return response()->json([
                    'message' => 'from_date and to_date are required (format: YYYYMMDD)'
                ], 400);
            }

            $dailyStats = ReportDashboardDaily::where('date_at', '>=', (int) $fromDate)
                ->where('date_at', '<=', (int) $toDate)
                ->orderBy('date_at', 'desc')
                ->get()
                ->map(function ($stat) {
                    return [
                        'date' => Carbon::createFromFormat('Ymd', (string) $stat->date_at)->format('Y-m-d'),
                        'total_orders' => $stat->total_orders,
                        'order_completed' => $stat->order_completed,
                        'order_pending' => $stat->order_pending,
                        'order_processing' => $stat->order_processing,
                        'order_in_progress' => $stat->order_in_progress,
                        'order_partial' => $stat->order_partial,
                        'order_canceled' => $stat->order_canceled,
                        'order_failed' => $stat->order_failed,
                        'total_revenue' => (float) $stat->total_revenue,
                        'total_charge' => (float) $stat->total_charge,
                        'total_cost' => (float) $stat->total_cost,
                        'total_profit' => (float) $stat->total_profit,
                        'total_refund' => (float) $stat->total_refund,
                    ];
                });

            return response()->json([
                'data' => $dailyStats,
                'message' => 'Daily breakdown retrieved successfully'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to retrieve daily breakdown',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
