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
            ->whereIn('type',[Dongtien::TYPE_DEPOSIT,Dongtien::TYPE_CHARGE,Dongtien::TYPE_REFUND,'withdraw'])
            ->orderBy('id')
            ->cursor();

        $userStats = [];
        $processedTransactionIds = [];

        foreach ($transactions as $transaction) {
            $userId = $transaction->user_id;
            // Lấy timestamp đầu ngày (00:00:00) từ created_at của transaction
            $dateAt = strtotime(date('Y-m-d', strtotime($transaction->created_at)));
            $key = "{$userId}_{$dateAt}";

            if (!isset($userStats[$key])) {
                $userStats[$key] = [
                    'user_id' => $userId,
                    'date_at' => $dateAt,
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
                    $userStats[$key]['total_deposit'] += abs($transaction->amount);
                    break;

                case Dongtien::TYPE_CHARGE:
                case 'charge':
                    // Mua hàng (trừ tiền)
                    $userStats[$key]['total_spending'] += abs($transaction->amount);
                    break;

                case Dongtien::TYPE_REFUND:
                case 'refund':
                    // Hoàn tiền (cộng tiền)
                    $userStats[$key]['total_refund'] += abs($transaction->amount);
                    break;

                case 'withdraw':
                    // Rút tiền (trừ tiền)
                    $userStats[$key]['total_withdraw'] += abs($transaction->amount);
                    break;
            }

            // Lưu ID để update scan sau
            $processedTransactionIds[] = $transaction->id;
        }

        // Cập nhật hoặc tạo mới báo cáo cho từng user theo ngày
        foreach ($userStats as $key => $stats) {
            try {
                $userId = $stats['user_id'];
                $dateAt = $stats['date_at'];

                $report = UserFinancialReport::firstOrNew([
                    'user_id' => $userId,
                    'date_at' => $dateAt
                ]);

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

                $report->save();
            } catch (\Throwable $th) {
                $this->error("Error updating report for user {$userId} on {$dateAt}: {$th->getMessage()}");
                continue;
            }
        }

        // Đánh dấu các giao dịch đã xử lý (bulk update cho hiệu suất tốt hơn)
        if (!empty($processedTransactionIds)) {
            try {
                Dongtien::whereIn('id', $processedTransactionIds)
                    ->update(['scan' => 1]);
                $this->info('Processed ' . count($processedTransactionIds) . ' transactions');
            } catch (\Throwable $th) {
                $this->error('Error marking transactions as scanned: ' . $th->getMessage());
            }
        }

        $this->info('User financial reports generated successfully');

        return 0;
    }
}
