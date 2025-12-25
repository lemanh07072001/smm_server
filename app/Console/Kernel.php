<?php

namespace App\Console;

use App\Console\Commands\CheckBank;
use App\Console\Commands\CheckOrderStatus;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected $commands = [
        CheckBank::class,
        CheckOrderStatus::class,
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

        $schedule->command('order:check-status')
            ->runInBackground()
            ->everyFiveMinutes()
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/order-status.log'));
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
