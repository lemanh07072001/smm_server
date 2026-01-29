<?php

namespace App\Console\Commands;

use App\Models\Dongtien;
use App\Models\Order;
use App\Models\UserFinancialReport;
use Illuminate\Console\Command;

class GenerateUserFinancialReport extends Command
{
    protected $signature = 'report:user-financial';

    protected $description = 'Tạo báo cáo thống kê tài chính cho từng user từ bảng dongtien';

    public function handle()
    {
        // Lấy các giao dịch chưa được scan
        $transactions = Dongtien::where('scan', 0)
            ->orderBy('id')
            ->cursor();

        $userStats = [];

        foreach ($transactions as $transaction) {
            $userId = $transaction->user_id;

            if (!isset($userStats[$userId])) {
                $userStats[$userId] = [
                    'total_deposit' => 0,
                    'total_spending' => 0,
                    'total_refund' => 0,
                    'total_withdraw' => 0,
                ];
            }

            // Tính toán theo loại giao dịch
            switch ($transaction->type) {
                case Dongtien::TYPE_DEPOSIT:
                case 'deposit':
                    // Nạp tiền (cộng tiền)
                    $userStats[$userId]['total_deposit'] += abs($transaction->amount);
                    break;

                case Dongtien::TYPE_CHARGE:
                case 'charge':
                    // Mua hàng (trừ tiền)
                    $userStats[$userId]['total_spending'] += abs($transaction->amount);
                    break;

                case Dongtien::TYPE_REFUND:
                case 'refund':
                    // Hoàn tiền (cộng tiền)
                    $userStats[$userId]['total_refund'] += abs($transaction->amount);
                    break;

                case 'withdraw':
                    // Rút tiền (trừ tiền)
                    $userStats[$userId]['total_withdraw'] += abs($transaction->amount);
                    break;
            }
        }

        // Cập nhật hoặc tạo mới báo cáo cho từng user
        foreach ($userStats as $userId => $stats) {
            try {
                $report = UserFinancialReport::firstOrNew(['user_id' => $userId]);

                // Cộng dồn các giá trị
                $report->total_deposit += $stats['total_deposit'];
                $report->total_spending += $stats['total_spending'];
                $report->total_refund += $stats['total_refund'];
                $report->total_withdraw += $stats['total_withdraw'];

                // Cập nhật số dư hiện tại từ bảng users
                $user = \App\Models\User::find($userId);
                if ($user) {
                    $report->current_balance = $user->balance;
                }

                // Cập nhật thống kê đơn hàng
                $orderStats = Order::where('user_id', $userId)
                    ->selectRaw('
                        COUNT(*) as total_orders,
                        SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as completed_orders
                    ', [Order::STATUS_COMPLETED])
                    ->first();

                $report->total_orders = $orderStats->total_orders ?? 0;
                $report->completed_orders = $orderStats->completed_orders ?? 0;

                $report->save();
            } catch (\Throwable $th) {
                $this->error("Error updating report for user {$userId}: {$th->getMessage()}");
                continue;
            }
        }

        // Đánh dấu các giao dịch đã xử lý
        foreach ($transactions as $transaction) {
            try {
                $transaction->update(['scan' => 1]);
            } catch (\Throwable $th) {
                continue;
            }
        }

        $this->info('User financial reports generated successfully');

        return 0;
    }
}
