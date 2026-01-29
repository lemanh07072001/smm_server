<?php

namespace App\Console\Commands;

use DB;
use App\Models\Order;
use Illuminate\Console\Command;

class UpdateOrderCompletedAt extends Command
{
    protected $signature = 'order:update-completed-at';

    protected $description = 'Cập nhật completed_at theo updated_at cho các đơn hàng';

    public function handle()
    {
        $this->info('Bắt đầu cập nhật completed_at...');

        // Cập nhật tất cả đơn hàng có completed_at = null
        $count = Order::whereNull('completed_at')
            ->update([
                'completed_at' => DB::raw('updated_at')
            ]);

        $this->info("Đã cập nhật {$count} đơn hàng.");

        return 0;
    }
}
