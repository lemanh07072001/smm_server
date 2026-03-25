<?php

namespace App\Console;

use App\Console\Commands\CheckBank;
use App\Console\Commands\ExpireDeposits;
use App\Console\Commands\GenerateOrderReport;
use App\Console\Commands\GenerateUserFinancialReport;
use App\Console\Commands\RefreshDashboardStats;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected $commands = [
        CheckBank::class,
        ExpireDeposits::class,
        GenerateOrderReport::class,
        GenerateUserFinancialReport::class,
        RefreshDashboardStats::class,
    ];

    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // $schedule->command('inspire')->hourly();
        $schedule->command('checkbank')
            ->runInBackground()
            ->everyMinute()
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('checkbank.txt'));

        // Quét orders STATUS_PENDING chưa được đẩy vào queue mỗi 30s
        $schedule->command('order_place scan')
            ->runInBackground()
            ->everyThirtySeconds()
            ->withoutOverlapping(5)
            ->appendOutputTo(storage_path('logs/place-order-scan.log'));

        // Kiểm tra trạng thái orders từ provider mỗi 30s
        $schedule->command('order_place status')
            ->runInBackground()
            ->everyThirtySeconds()
            ->withoutOverlapping(5)
            ->appendOutputTo(storage_path('logs/place-order-status.log'));

        // Kiểm tra trạng thái orders đang chờ hoàn mỗi 1 phút
        $schedule->command('order_place refund-check')
            ->runInBackground()
            ->everyMinute()
            ->withoutOverlapping(5)
            ->appendOutputTo(storage_path('logs/place-order-refund-check.log'));

        // Refresh dashboard stats mỗi 5 phút → lưu vào ReportDashboardDaily + Redis
        $schedule->command('dashboard:refresh')
            ->runInBackground()
            ->everyFiveMinutes()
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/dashboard-refresh.log'));

        // Kiểm tra số dư nhà cung cấp mỗi 5 phút
        $schedule->command('provider:check-balance')
            ->runInBackground()
            ->everyFiveMinutes()
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/provider-balance.log'));

        // Thống kê đơn hàng mỗi 10 phút
        $schedule->command('report:order')
            ->runInBackground()
            ->everyTenMinutes()
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/order-report.log'));

        // Thống kê tài chính user mỗi 10 phút
        $schedule->command('report:user-financial')
            ->runInBackground()
            ->everyTenMinutes()
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/user-financial-report.log'));

        // Expire các giao dịch pending quá 5 phút
        $schedule->command('deposits:expire')
            ->runInBackground()
            ->everyMinute()
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/deposits-expire.log'));

        // Lưu activity logs từ Redis vào MongoDB mỗi 1 phút
        $schedule->command('activity_log:save')
            ->runInBackground()
            ->everyMinute()
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/activity-log-save.log'));
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__ . '/Commands');

        require base_path('routes/console.php');
    }
}
