<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\Providers\ProviderFactory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CheckOrderStatus extends Command
{
    protected $signature = 'order:check-status';

    protected $description = 'Kiểm tra và cập nhật trạng thái đơn hàng từ provider';

    public function handle()
    {
        $orders = Order::with(['service.providerService.provider'])
            ->whereIn('status', [
                Order::STATUS_PENDING,
                Order::STATUS_PROCESSING,
                Order::STATUS_IN_PROGRESS,
            ])
            ->whereNotNull('provider_order_id')
            ->get();

        if ($orders->isEmpty()) {
            return 0;
        }

        foreach ($orders as $order) {
            $this->processProviderOrders($order);
        }

        return 0;
    }

    private function processProviderOrders(Order $order): void
    {
        $provider = $order->service->providerService->provider;

        if (!ProviderFactory::isSupported($provider->code)) {
            return;
        }

        $providerService = ProviderFactory::make($provider);

        try {
            $statusResponse = $providerService->getOrderStatus($order->provider_order_id);

            if ($statusResponse['success']) {
                $this->updateOrder($order, $statusResponse,$providerService);
            }
        } catch (\Exception $e) {
            Log::error('CheckOrderStatus error', [
                'provider' => $provider->code,
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function updateOrder(Order $order, $statusResponse, $providerService): void
    {
        $data = $statusResponse['data'] ?? [];
        $updateData = [];

        if (isset($data['start_count'])) {
            $updateData['start_count'] = $data['start_count'];
        }

        if (isset($data['remains'])) {
            $updateData['remains'] = $data['remains'];
        }

        if (!empty($data['status'])) {
            $updateData['status'] = $providerService->mapProviderStatus($data['status']);
        }

        if (!empty($updateData)) {
            $order->update($updateData);
            echo '1';
        }
    }
}
