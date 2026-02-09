<?php

namespace App\Console\Commands;

use App\Models\AffiliateCommission;
use App\Models\Order;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BackfillAffiliateCommissions extends Command
{
    protected $signature = 'affiliate:backfill {--user= : Chỉ xử lý cho user_id cụ thể} {--dry-run : Chạy thử không lưu DB}';

    protected $description = 'Tính lại commission affiliate cho các đơn hàng completed đã có sẵn';

    public function handle()
    {
        $dryRun = $this->option('dry-run');
        $userId = $this->option('user');

        if ($dryRun) {
            $this->warn('=== DRY RUN - Không lưu vào DB ===');
        }

        // Lấy danh sách user có referred_by
        $usersQuery = User::whereNotNull('referred_by');
        if ($userId) {
            $usersQuery->where('id', $userId);
        }

        $users = $usersQuery->get();

        if ($users->isEmpty()) {
            $this->info('Không có user nào có referred_by.');
            return 0;
        }

        $this->info("Tìm thấy {$users->count()} user có referred_by");

        $totalCreated = 0;
        $totalAmount = 0;

        foreach ($users as $user) {
            $referrer = User::find($user->referred_by);
            if (!$referrer) {
                $this->warn("  User #{$user->id}: referrer #{$user->referred_by} không tồn tại, bỏ qua.");
                continue;
            }

            // Lấy các order completed chưa có commission
            $orders = Order::where('user_id', $user->id)
                ->where('status', Order::STATUS_COMPLETED)
                ->where('charge_amount', '>', 0)
                ->whereNotIn('id', function ($query) {
                    $query->select('order_id')->from('affiliate_commissions');
                })
                ->get();

            if ($orders->isEmpty()) {
                continue;
            }

            $this->info("  User #{$user->id} ({$user->name}) → Referrer #{$referrer->id} ({$referrer->name}): {$orders->count()} đơn");

            foreach ($orders as $order) {
                $commissionRate = 10.00;
                $orderAmount = (float) $order->charge_amount;
                $commissionAmount = round($orderAmount * $commissionRate / 100, 2);

                if ($commissionAmount <= 0) {
                    continue;
                }

                $this->line("    Order #{$order->id}: {$orderAmount} × 10% = {$commissionAmount}");

                if (!$dryRun) {
                    DB::beginTransaction();
                    try {
                        AffiliateCommission::create([
                            'user_id' => $referrer->id,
                            'order_id' => $order->id,
                            'referred_user_id' => $user->id,
                            'order_amount' => $orderAmount,
                            'commission_rate' => $commissionRate,
                            'commission_amount' => $commissionAmount,
                        ]);

                        $referrer->increment('affiliate_balance', $commissionAmount);
                        DB::commit();
                    } catch (\Exception $e) {
                        DB::rollBack();
                        $this->error("    Lỗi order #{$order->id}: {$e->getMessage()}");
                        continue;
                    }
                }

                $totalCreated++;
                $totalAmount += $commissionAmount;
            }
        }

        $this->newLine();
        $this->info("=== Kết quả ===");
        $this->info("Commission tạo: {$totalCreated}");
        $this->info("Tổng hoa hồng: " . number_format($totalAmount, 2));

        if ($dryRun) {
            $this->warn('Đây là dry-run. Chạy lại không có --dry-run để lưu vào DB.');
        }

        return 0;
    }
}
