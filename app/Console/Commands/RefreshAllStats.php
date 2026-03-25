<?php

namespace App\Console\Commands;

use App\Models\Dongtien;
use App\Models\Order;
use App\Models\ReportOrderDaily;
use App\Models\UserFinancialReport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RefreshAllStats extends Command
{
    protected $signature = 'report:refresh';

    protected $description = 'Quét orders + dongtien scan=0, cộng dồn vào report tables (chạy mỗi giờ)';

    private const EXCLUDED_USER_IDS = []; // [1, 16011];

    private const TERMINAL_STATUSES = [
        Order::STATUS_COMPLETED,
        Order::STATUS_PARTIAL,
        Order::STATUS_CANCELED,
        Order::STATUS_FAILED,
    ];

    private const SCANNABLE_DONGTIEN_TYPES = [
        Dongtien::TYPE_DEPOSIT,
        Dongtien::TYPE_CHARGE,
        Dongtien::TYPE_REFUND,
        'withdraw',
    ];

    public function handle(): int
    {
        $startTime = microtime(true);

        $orderCount      = $this->processOrderReport();
        $transactionCount = $this->processUserFinancialReport();

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

            if ($order->status === Order::STATUS_FAILED) {
                $groups[$key]['total_refund'] += (float) $order->charge_amount;
            } else {
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
}
