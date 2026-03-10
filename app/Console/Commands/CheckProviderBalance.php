<?php

namespace App\Console\Commands;

use App\Models\Provider;
use App\Services\Providers\ProviderFactory;
use Illuminate\Console\Command;

class CheckProviderBalance extends Command
{
    protected $signature = 'provider:check-balance {provider? : Code của provider, bỏ trống để check tất cả}';

    protected $description = 'Kiểm tra số dư tài khoản tại các nhà cung cấp và cập nhật vào database';

    public function handle(): void
    {
        $code = $this->argument('provider');

        $query = Provider::where('is_active', true);

        if ($code) {
            $query->where('code', $code);
        }

        $providers = $query->get();

        if ($providers->isEmpty()) {
            $this->error($code ? "Không tìm thấy provider: {$code}" : 'Không có provider nào đang active.');
            return;
        }

        $this->info('Bắt đầu kiểm tra số dư: ' . now()->format('Y-m-d H:i:s'));
        $this->newLine();

        $rows = [];

        foreach ($providers as $provider) {
            if (!ProviderFactory::isSupported($provider->code)) {
                $rows[] = [$provider->name, $provider->code, 'N/A', 'Chưa hỗ trợ'];
                continue;
            }

            try {
                $service = ProviderFactory::make($provider);
                $response = $service->getBalance();
                $balance  = $service->parseBalanceResponse($response);

                if ($balance !== null) {
                    $provider->update([
                        'balance'            => $balance['balance'],
                        'balance_currency'   => $balance['currency'],
                        'balance_updated_at' => now(),
                    ]);

                    $display = number_format($balance['balance'], 2) . ' ' . $balance['currency'];
                    $rows[] = [$provider->name, $provider->code, $display, 'OK'];
                    $this->line("  <info>✓</info> {$provider->name} ({$provider->code}): {$display}");
                } else {
                    $rows[] = [$provider->name, $provider->code, '-', 'Không parse được balance'];
                    $this->line("  <comment>?</comment> {$provider->name} ({$provider->code}): không parse được — " . $response['body']);
                }
            } catch (\Exception $e) {
                $rows[] = [$provider->name, $provider->code, '-', 'Lỗi: ' . $e->getMessage()];
                $this->line("  <error>✗</error> {$provider->name} ({$provider->code}): " . $e->getMessage());
            }
        }

        $this->newLine();
        $this->table(['Tên', 'Code', 'Số dư', 'Trạng thái'], $rows);
        $this->info('Hoàn thành: ' . now()->format('Y-m-d H:i:s'));
    }
}
