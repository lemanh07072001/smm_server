<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ReportOrderDaily;
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
            $yesterdayStats = ReportOrderDaily::where('date_at', $yesterdayDate)
                ->selectRaw('
                    SUM(order_pending) as order_pending,
                    SUM(order_processing) as order_processing,
                    SUM(order_in_progress) as order_in_progress,
                    SUM(order_completed) as order_completed,
                    SUM(order_partial) as order_partial,
                    SUM(order_canceled) as order_canceled,
                    SUM(order_refunded) as order_refunded,
                    SUM(order_failed) as order_failed,
                    SUM(total_charge) as total_charge,
                    SUM(total_cost) as total_cost,
                    SUM(total_profit) as total_profit,
                    SUM(total_refund) as total_refund,
                    SUM(total_quantity) as total_quantity
                ')
                ->first();

            // Last 7 days statistics
            $last7DaysStats = ReportOrderDaily::where('date_at', '>=', $last7DaysDate)
                ->where('date_at', '<', $todayDate)
                ->selectRaw('
                    SUM(order_pending) as order_pending,
                    SUM(order_processing) as order_processing,
                    SUM(order_in_progress) as order_in_progress,
                    SUM(order_completed) as order_completed,
                    SUM(order_partial) as order_partial,
                    SUM(order_canceled) as order_canceled,
                    SUM(order_refunded) as order_refunded,
                    SUM(order_failed) as order_failed,
                    SUM(total_charge) as total_charge,
                    SUM(total_cost) as total_cost,
                    SUM(total_profit) as total_profit,
                    SUM(total_refund) as total_refund,
                    SUM(total_quantity) as total_quantity
                ')
                ->first();

            // Last 30 days statistics
            $last30DaysStats = ReportOrderDaily::where('date_at', '>=', $last30DaysDate)
                ->where('date_at', '<', $todayDate)
                ->selectRaw('
                    SUM(order_pending) as order_pending,
                    SUM(order_processing) as order_processing,
                    SUM(order_in_progress) as order_in_progress,
                    SUM(order_completed) as order_completed,
                    SUM(order_partial) as order_partial,
                    SUM(order_canceled) as order_canceled,
                    SUM(order_refunded) as order_refunded,
                    SUM(order_failed) as order_failed,
                    SUM(total_charge) as total_charge,
                    SUM(total_cost) as total_cost,
                    SUM(total_profit) as total_profit,
                    SUM(total_refund) as total_refund,
                    SUM(total_quantity) as total_quantity
                ')
                ->first();

            // Calculate total orders
            $yesterdayTotalOrders = ($yesterdayStats->order_pending ?? 0) +
                                   ($yesterdayStats->order_processing ?? 0) +
                                   ($yesterdayStats->order_in_progress ?? 0) +
                                   ($yesterdayStats->order_completed ?? 0) +
                                   ($yesterdayStats->order_partial ?? 0) +
                                   ($yesterdayStats->order_canceled ?? 0) +
                                   ($yesterdayStats->order_refunded ?? 0) +
                                   ($yesterdayStats->order_failed ?? 0);

            $last7DaysTotalOrders = ($last7DaysStats->order_pending ?? 0) +
                                   ($last7DaysStats->order_processing ?? 0) +
                                   ($last7DaysStats->order_in_progress ?? 0) +
                                   ($last7DaysStats->order_completed ?? 0) +
                                   ($last7DaysStats->order_partial ?? 0) +
                                   ($last7DaysStats->order_canceled ?? 0) +
                                   ($last7DaysStats->order_refunded ?? 0) +
                                   ($last7DaysStats->order_failed ?? 0);

            $last30DaysTotalOrders = ($last30DaysStats->order_pending ?? 0) +
                                    ($last30DaysStats->order_processing ?? 0) +
                                    ($last30DaysStats->order_in_progress ?? 0) +
                                    ($last30DaysStats->order_completed ?? 0) +
                                    ($last30DaysStats->order_partial ?? 0) +
                                    ($last30DaysStats->order_canceled ?? 0) +
                                    ($last30DaysStats->order_refunded ?? 0) +
                                    ($last30DaysStats->order_failed ?? 0);

            return response()->json([
                'data' => [
                    'yesterday' => [
                        'date' => $yesterday->format('Y-m-d'),
                        'total_orders' => $yesterdayTotalOrders,
                        'order_completed' => $yesterdayStats->order_completed ?? 0,
                        'order_pending' => $yesterdayStats->order_pending ?? 0,
                        'order_processing' => $yesterdayStats->order_processing ?? 0,
                        'order_in_progress' => $yesterdayStats->order_in_progress ?? 0,
                        'order_partial' => $yesterdayStats->order_partial ?? 0,
                        'order_canceled' => $yesterdayStats->order_canceled ?? 0,
                        'order_refunded' => $yesterdayStats->order_refunded ?? 0,
                        'order_failed' => $yesterdayStats->order_failed ?? 0,
                        'total_charge' => (float) ($yesterdayStats->total_charge ?? 0),
                        'total_cost' => (float) ($yesterdayStats->total_cost ?? 0),
                        'total_profit' => (float) ($yesterdayStats->total_profit ?? 0),
                        'total_refund' => (float) ($yesterdayStats->total_refund ?? 0),
                        'total_quantity' => $yesterdayStats->total_quantity ?? 0,
                    ],
                    'last_7_days' => [
                        'date_from' => $today->copy()->subDays(7)->format('Y-m-d'),
                        'date_to' => $yesterday->format('Y-m-d'),
                        'total_orders' => $last7DaysTotalOrders,
                        'order_completed' => $last7DaysStats->order_completed ?? 0,
                        'order_pending' => $last7DaysStats->order_pending ?? 0,
                        'order_processing' => $last7DaysStats->order_processing ?? 0,
                        'order_in_progress' => $last7DaysStats->order_in_progress ?? 0,
                        'order_partial' => $last7DaysStats->order_partial ?? 0,
                        'order_canceled' => $last7DaysStats->order_canceled ?? 0,
                        'order_refunded' => $last7DaysStats->order_refunded ?? 0,
                        'order_failed' => $last7DaysStats->order_failed ?? 0,
                        'total_charge' => (float) ($last7DaysStats->total_charge ?? 0),
                        'total_cost' => (float) ($last7DaysStats->total_cost ?? 0),
                        'total_profit' => (float) ($last7DaysStats->total_profit ?? 0),
                        'total_refund' => (float) ($last7DaysStats->total_refund ?? 0),
                        'total_quantity' => $last7DaysStats->total_quantity ?? 0,
                    ],
                    'last_30_days' => [
                        'date_from' => $today->copy()->subDays(30)->format('Y-m-d'),
                        'date_to' => $yesterday->format('Y-m-d'),
                        'total_orders' => $last30DaysTotalOrders,
                        'order_completed' => $last30DaysStats->order_completed ?? 0,
                        'order_pending' => $last30DaysStats->order_pending ?? 0,
                        'order_processing' => $last30DaysStats->order_processing ?? 0,
                        'order_in_progress' => $last30DaysStats->order_in_progress ?? 0,
                        'order_partial' => $last30DaysStats->order_partial ?? 0,
                        'order_canceled' => $last30DaysStats->order_canceled ?? 0,
                        'order_refunded' => $last30DaysStats->order_refunded ?? 0,
                        'order_failed' => $last30DaysStats->order_failed ?? 0,
                        'total_charge' => (float) ($last30DaysStats->total_charge ?? 0),
                        'total_cost' => (float) ($last30DaysStats->total_cost ?? 0),
                        'total_profit' => (float) ($last30DaysStats->total_profit ?? 0),
                        'total_refund' => (float) ($last30DaysStats->total_refund ?? 0),
                        'total_quantity' => $last30DaysStats->total_quantity ?? 0,
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

            $stats = ReportOrderDaily::where('date_at', '>=', (int) $fromDate)
                ->where('date_at', '<=', (int) $toDate)
                ->selectRaw('
                    SUM(order_pending) as order_pending,
                    SUM(order_processing) as order_processing,
                    SUM(order_in_progress) as order_in_progress,
                    SUM(order_completed) as order_completed,
                    SUM(order_partial) as order_partial,
                    SUM(order_canceled) as order_canceled,
                    SUM(order_refunded) as order_refunded,
                    SUM(order_failed) as order_failed,
                    SUM(total_charge) as total_charge,
                    SUM(total_cost) as total_cost,
                    SUM(total_profit) as total_profit,
                    SUM(total_refund) as total_refund,
                    SUM(total_quantity) as total_quantity
                ')
                ->first();

            $totalOrders = ($stats->order_pending ?? 0) +
                          ($stats->order_processing ?? 0) +
                          ($stats->order_in_progress ?? 0) +
                          ($stats->order_completed ?? 0) +
                          ($stats->order_partial ?? 0) +
                          ($stats->order_canceled ?? 0) +
                          ($stats->order_refunded ?? 0) +
                          ($stats->order_failed ?? 0);

            return response()->json([
                'data' => [
                    'date_from' => Carbon::createFromFormat('Ymd', $fromDate)->format('Y-m-d'),
                    'date_to' => Carbon::createFromFormat('Ymd', $toDate)->format('Y-m-d'),
                    'total_orders' => $totalOrders,
                    'order_completed' => $stats->order_completed ?? 0,
                    'order_pending' => $stats->order_pending ?? 0,
                    'order_processing' => $stats->order_processing ?? 0,
                    'order_in_progress' => $stats->order_in_progress ?? 0,
                    'order_partial' => $stats->order_partial ?? 0,
                    'order_canceled' => $stats->order_canceled ?? 0,
                    'order_refunded' => $stats->order_refunded ?? 0,
                    'order_failed' => $stats->order_failed ?? 0,
                    'total_charge' => (float) ($stats->total_charge ?? 0),
                    'total_cost' => (float) ($stats->total_cost ?? 0),
                    'total_profit' => (float) ($stats->total_profit ?? 0),
                    'total_refund' => (float) ($stats->total_refund ?? 0),
                    'total_quantity' => $stats->total_quantity ?? 0,
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

            $dailyStats = ReportOrderDaily::where('date_at', '>=', (int) $fromDate)
                ->where('date_at', '<=', (int) $toDate)
                ->selectRaw('
                    date_at,
                    SUM(order_pending) as order_pending,
                    SUM(order_processing) as order_processing,
                    SUM(order_in_progress) as order_in_progress,
                    SUM(order_completed) as order_completed,
                    SUM(order_partial) as order_partial,
                    SUM(order_canceled) as order_canceled,
                    SUM(order_refunded) as order_refunded,
                    SUM(order_failed) as order_failed,
                    SUM(total_charge) as total_charge,
                    SUM(total_cost) as total_cost,
                    SUM(total_profit) as total_profit,
                    SUM(total_refund) as total_refund,
                    SUM(total_quantity) as total_quantity
                ')
                ->groupBy('date_at')
                ->orderBy('date_at', 'desc')
                ->get()
                ->map(function ($stat) {
                    $totalOrders = ($stat->order_pending ?? 0) +
                                  ($stat->order_processing ?? 0) +
                                  ($stat->order_in_progress ?? 0) +
                                  ($stat->order_completed ?? 0) +
                                  ($stat->order_partial ?? 0) +
                                  ($stat->order_canceled ?? 0) +
                                  ($stat->order_refunded ?? 0) +
                                  ($stat->order_failed ?? 0);

                    return [
                        'date' => Carbon::createFromFormat('Ymd', (string) $stat->date_at)->format('Y-m-d'),
                        'total_orders' => $totalOrders,
                        'order_completed' => $stat->order_completed,
                        'order_pending' => $stat->order_pending,
                        'order_processing' => $stat->order_processing,
                        'order_in_progress' => $stat->order_in_progress,
                        'order_partial' => $stat->order_partial,
                        'order_canceled' => $stat->order_canceled,
                        'order_refunded' => $stat->order_refunded,
                        'order_failed' => $stat->order_failed,
                        'total_charge' => (float) $stat->total_charge,
                        'total_cost' => (float) $stat->total_cost,
                        'total_profit' => (float) $stat->total_profit,
                        'total_refund' => (float) $stat->total_refund,
                        'total_quantity' => $stat->total_quantity,
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
