<?php

namespace App\Console;

use App\Console\Commands\CheckBank;
use App\Console\Commands\GenerateOrderReport;
use App\Console\Commands\GenerateUserFinancialReport;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected $commands = [
        CheckBank::class,
        GenerateOrderReport::class,
        GenerateUserFinancialReport::class,
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

        // Quét orders STATUS_PENDING chưa được đẩy vào queue mỗi 5 phút
        $schedule->command('order_place scan')
            ->runInBackground()
            ->everyFiveMinutes()
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/place-order-scan.log'));

        // Kiểm tra trạng thái orders từ provider mỗi 5 phút
        $schedule->command('order_place status')
            ->runInBackground()
            ->everyFiveMinutes()
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/place-order-status.log'));

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
