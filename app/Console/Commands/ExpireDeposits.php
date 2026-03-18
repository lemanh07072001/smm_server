<?php

namespace App\Console\Commands;

use App\Models\BankAuto;
use App\Models\DepositLog;
use Illuminate\Console\Command;

class ExpireDeposits extends Command
{
    protected $signature = 'deposits:expire';
    protected $description = 'Đánh dấu expired và xoá các giao dịch pending hết hạn';

    public function handle(): void
    {
        $this->markExpired();
        $this->deleteExpired();
    }

    /**
     * Bước 1: Đánh dấu pending hết hạn → expired
     */
    private function markExpired(): void
    {
        $records = BankAuto::where('status', 'pending')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->get();

        foreach ($records as $record) {
            $record->update(['status' => 'expired']);

            try {
                DepositLog::create([
                    'step'         => 'mark_expired',
                    'status'       => 'warning',
                    'source'       => 'system',
                    'tid'          => $record->tid,
                    'user_id'      => $record->user_id,
                    'amount'       => $record->amount,
                    'bank_auto_id' => $record->id,
                    'deposit_code' => $record->transaction_code,
                    'message'      => 'Giao dịch pending hết hạn, chuyển sang expired',
                ]);
            } catch (\Exception) {}
        }

        if ($records->count() > 0) {
            $this->info("Đã đánh dấu {$records->count()} giao dịch expired.");
        }
    }

    /**
     * Bước 2: Xoá các bản ghi expired không có tid (user tạo QR nhưng không chuyển khoản)
     */
    private function deleteExpired(): void
    {
        $count = BankAuto::where('status', 'expired')
            ->whereNull('tid')
            ->delete();

        if ($count > 0) {
            $this->info("Đã xoá {$count} giao dịch expired không có tid.");
        }
    }
}
