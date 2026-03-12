<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OrderCompletionStat;
use App\Models\ReportOrderDaily;
use Carbon\Carbon;
use Illuminate\Http\Request;

class StatisticsController extends Controller
{
    /**
     * Get revenue and order statistics for yesterday, last 7 days, or last 30 days
     *
     * @param Request $request
     * @queryParam period string Period to get statistics for (yesterday, last_7_days, last_30_days). If not provided, returns all.
     */
    public function summary(Request $request)
    {
        try {
            $period = $request->input('period'); // yesterday, last_7_days, last_30_days
            $today = Carbon::today();
            $yesterday = Carbon::yesterday();

            // Calculate date ranges as timestamps
            $yesterdayStart = $yesterday->startOfDay()->timestamp;
            $yesterdayEnd = $yesterday->endOfDay()->timestamp;
            $last7DaysStart = $today->copy()->subDays(7)->startOfDay()->timestamp;
            $last30DaysStart = $today->copy()->subDays(30)->startOfDay()->timestamp;
            $todayStart = $today->startOfDay()->timestamp;

            $data = [];

            // If no period specified or period is 'yesterday', include yesterday stats
            if (!$period || $period === 'yesterday') {
                $yesterdayStats = ReportOrderDaily::where('date_at', '>=', $yesterdayStart)
                    ->where('date_at', '<=', $yesterdayEnd)
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

                $yesterdayTotalOrders = ($yesterdayStats->order_pending ?? 0) +
                                       ($yesterdayStats->order_processing ?? 0) +
                                       ($yesterdayStats->order_in_progress ?? 0) +
                                       ($yesterdayStats->order_completed ?? 0) +
                                       ($yesterdayStats->order_partial ?? 0) +
                                       ($yesterdayStats->order_canceled ?? 0) +
                                       ($yesterdayStats->order_refunded ?? 0) +
                                       ($yesterdayStats->order_failed ?? 0);

                $data['yesterday'] = [
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
                ];
            }

            // If no period specified or period is 'last_7_days', include last 7 days stats
            if (!$period || $period === 'last_7_days') {
                $last7DaysStats = ReportOrderDaily::where('date_at', '>=', $last7DaysStart)
                    ->where('date_at', '<', $todayStart)
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

                $last7DaysTotalOrders = ($last7DaysStats->order_pending ?? 0) +
                                       ($last7DaysStats->order_processing ?? 0) +
                                       ($last7DaysStats->order_in_progress ?? 0) +
                                       ($last7DaysStats->order_completed ?? 0) +
                                       ($last7DaysStats->order_partial ?? 0) +
                                       ($last7DaysStats->order_canceled ?? 0) +
                                       ($last7DaysStats->order_refunded ?? 0) +
                                       ($last7DaysStats->order_failed ?? 0);

                $data['last_7_days'] = [
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
                ];
            }

            // If no period specified or period is 'last_30_days', include last 30 days stats
            if (!$period || $period === 'last_30_days') {
                $last30DaysStats = ReportOrderDaily::where('date_at', '>=', $last30DaysStart)
                    ->where('date_at', '<', $todayStart)
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

                $last30DaysTotalOrders = ($last30DaysStats->order_pending ?? 0) +
                                        ($last30DaysStats->order_processing ?? 0) +
                                        ($last30DaysStats->order_in_progress ?? 0) +
                                        ($last30DaysStats->order_completed ?? 0) +
                                        ($last30DaysStats->order_partial ?? 0) +
                                        ($last30DaysStats->order_canceled ?? 0) +
                                        ($last30DaysStats->order_refunded ?? 0) +
                                        ($last30DaysStats->order_failed ?? 0);

                $data['last_30_days'] = [
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
                ];
            }

            return response()->json([
                'data' => $data,
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
                    'message' => 'from_date and to_date are required (format: Y-m-d, example: 2026-01-01)'
                ], 400);
            }

            $fromTimestamp = Carbon::createFromFormat('Y-m-d', $fromDate)->startOfDay()->timestamp;
            $toTimestamp = Carbon::createFromFormat('Y-m-d', $toDate)->endOfDay()->timestamp;

            $stats = ReportOrderDaily::where('date_at', '>=', $fromTimestamp)
                ->where('date_at', '<=', $toTimestamp)
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
                    'date_from' => $fromDate,
                    'date_to' => $toDate,
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
     * Thống kê thời gian hoàn thành đơn hàng
     * Lấy 10 đơn gần nhất có quantity từ 1-1000, tính thời gian trung bình
     */
    public function orderCompletionTime(Request $request)
    {
        try {
            $query = OrderCompletionStat::where('quantity', '>=', 1)
                ->where('quantity', '<=', 1000);

            if ($request->filled('service_id')) {
                $query->where('service_id', $request->integer('service_id'));
            }

            $stats = $query->orderBy('completed_at', 'desc')->limit(10)->get();

            $count = $stats->count();

            if ($count === 0) {
                return response()->json([
                    'data' => [
                        'avg_completion_minutes' => null,
                        'avg_completion_text'    => null,
                        'based_on'               => 0,
                        'samples'                => [],
                    ],
                    'message' => 'Chưa có dữ liệu'
                ]);
            }

            $avgMinutes = (int) round($stats->avg('completion_minutes'));

            return response()->json([
                'data' => [
                    'avg_completion_minutes' => $avgMinutes,
                    'avg_completion_text'    => $this->formatMinutes($avgMinutes),
                    'based_on'               => $count,
                    'samples'                => $stats->map(fn($s) => [
                        'order_id'           => $s->order_id,
                        'quantity'           => $s->quantity,
                        'completion_minutes' => $s->completion_minutes,
                        'completion_text'    => $this->formatMinutes($s->completion_minutes),
                        'completed_at'       => $s->completed_at?->toDateTimeString(),
                    ]),
                ],
                'message' => 'Thống kê thời gian hoàn thành đơn hàng'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Lỗi khi lấy thống kê',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    private function formatMinutes(int $minutes): string
    {
        if ($minutes < 60) {
            return "{$minutes} phút";
        }

        $hours = intdiv($minutes, 60);
        $mins  = $minutes % 60;

        if ($mins === 0) {
            return "{$hours} giờ";
        }

        return "{$hours} giờ {$mins} phút";
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
                    'message' => 'from_date and to_date are required (format: Y-m-d, example: 2026-01-01)'
                ], 400);
            }

            $fromTimestamp = Carbon::createFromFormat('Y-m-d', $fromDate)->startOfDay()->timestamp;
            $toTimestamp = Carbon::createFromFormat('Y-m-d', $toDate)->endOfDay()->timestamp;

            $dailyStats = ReportOrderDaily::where('date_at', '>=', $fromTimestamp)
                ->where('date_at', '<=', $toTimestamp)
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
                        'date' => Carbon::createFromTimestamp($stat->date_at)->format('Y-m-d'),
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
