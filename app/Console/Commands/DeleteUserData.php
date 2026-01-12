<?php

namespace App\Console\Commands;

use App\Models\BankAuto;
use App\Models\Dongtien;
use App\Models\Order;
use App\Models\OrderActivityLog;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DeleteUserData extends Command
{
    protected $signature = 'user:delete-data {--email=admin@admin.com} {--id=1} {--force}';

    protected $description = 'Xóa dữ liệu liên quan đến user: Orders, Dongtien, BankAuto, Activity Logs';

    public function handle()
    {
        $email = $this->option('email');
        $userId = $this->option('id');
        $force = $this->option('force');

        // Tìm user
        $user = User::where('id', $userId)
            ->where('email', $email)
            ->first();

        if (!$user) {
            $this->error("❌ Không tìm thấy user với email: {$email} và id: {$userId}");
            return 1;
        }

        $this->info("🔍 Tìm thấy user: {$user->name} ({$user->email})");

        // Hiển thị thống kê dữ liệu
        $stats = $this->getUserDataStats($user->id);
        $this->displayStats($stats);

        if (!$force) {
            if (!$this->confirm('⚠️  Bạn có chắc chắn muốn xóa TẤT CẢ dữ liệu của user này?', false)) {
                $this->info('Đã hủy.');
                return 0;
            }
        }

        DB::beginTransaction();
        try {
            $this->info("\n🗑️  Bắt đầu xóa dữ liệu...");

            // Xóa Orders
            $ordersCount = Order::where('user_id', $user->id)->count();
            if ($ordersCount > 0) {
                Order::where('user_id', $user->id)->delete();
                $this->info("  ✅ Đã xóa {$ordersCount} orders");
            }

            // Xóa Dongtien (Transactions)
            $dongtienCount = Dongtien::where('user_id', $user->id)->count();
            if ($dongtienCount > 0) {
                Dongtien::where('user_id', $user->id)->delete();
                $this->info("  ✅ Đã xóa {$dongtienCount} dòng tiền");
            }

            // Xóa BankAuto
            $bankAutoCount = BankAuto::where('user_id', $user->id)->count();
            if ($bankAutoCount > 0) {
                BankAuto::where('user_id', $user->id)->delete();
                $this->info("  ✅ Đã xóa {$bankAutoCount} bank auto records");
            }

            // Xóa OrderActivityLog (MongoDB)
            try {
                $activityLogCount = OrderActivityLog::where('user_id', $user->id)->count();
                if ($activityLogCount > 0) {
                    OrderActivityLog::where('user_id', $user->id)->delete();
                    $this->info("  ✅ Đã xóa {$activityLogCount} activity logs");
                }
            } catch (\Exception $e) {
                $this->warn("  ⚠️  Không thể xóa activity logs: " . $e->getMessage());
            }

            DB::commit();

            $this->info("\n✅ Hoàn thành! Đã xóa tất cả dữ liệu liên quan đến user.");
            $this->info("📝 User vẫn còn trong database, chỉ xóa dữ liệu liên quan.");

            return 0;

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Error deleting user data', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            $this->error("❌ Lỗi khi xóa dữ liệu: " . $e->getMessage());
            return 1;
        }
    }

    /**
     * Lấy thống kê dữ liệu của user
     */
    private function getUserDataStats(int $userId): array
    {
        return [
            'orders' => Order::where('user_id', $userId)->count(),
            'dongtien' => Dongtien::where('user_id', $userId)->count(),
            'bank_auto' => BankAuto::where('user_id', $userId)->count(),
            'activity_logs' => OrderActivityLog::where('user_id', $userId)->count(),
        ];
    }

    /**
     * Hiển thị thống kê
     */
    private function displayStats(array $stats): void
    {
        $this->info("\n📊 Thống kê dữ liệu sẽ bị xóa:");
        $this->table(
            ['Loại dữ liệu', 'Số lượng'],
            [
                ['Orders', $stats['orders']],
                ['Dòng tiền (Dongtien)', $stats['dongtien']],
                ['Bank Auto', $stats['bank_auto']],
                ['Activity Logs', $stats['activity_logs']],
            ]
        );
    }
}
