<?php

namespace App\Console;

use App\Console\Commands\ActivateScheduledOrders;
use App\Console\Commands\CheckBank;
use App\Console\Commands\ExpireDeposits;
use App\Console\Commands\RefreshAllStats;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected $commands = [
        ActivateScheduledOrders::class,
        CheckBank::class,
        ExpireDeposits::class,
        RefreshAllStats::class,
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

        // Kiểm tra số dư nhà cung cấp mỗi 5 phút
        $schedule->command('provider:check-balance')
            ->runInBackground()
            ->everyFiveMinutes()
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/provider-balance.log'));

        // Quét orders + dongtien, cập nhật report tables + cache dashboard mỗi 5 phút
        $schedule->command('report:refresh')
            ->runInBackground()
            ->everyFiveMinutes()
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/report-refresh.log'));

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

        // Kích hoạt đơn hẹn giờ đã đến giờ chạy (mỗi 1 phút)
        $schedule->command('order:activate-scheduled')
            ->runInBackground()
            ->everyMinute()
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/activate-scheduled.log'));
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
