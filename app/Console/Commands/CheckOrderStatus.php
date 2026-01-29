<?php

namespace App\Console\Commands;

use App\Events\OrderStatusUpdated;
use App\Models\Order;
use App\Models\Dongtien;
use App\Services\Providers\ProviderFactory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CheckOrderStatus extends Command
{
    protected $signature = 'order:check-status';

    protected $description = 'Kiểm tra và cập nhật trạng thái đơn hàng từ provider';

    public function handle()
    {
        $orders = Order::with(['service.providerService.provider'])
            ->whereNotIn('status', [
                Order::STATUS_COMPLETED,
                Order::STATUS_FAILED,
                Order::STATUS_CANCELED,
                Order::STATUS_PARTIAL,
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
                $this->updateOrder($order, $statusResponse, $providerService);
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
        $responseData = $statusResponse['data'] ?? [];
        $providerOrderId = $order->provider_order_id;

        // Parse response - có thể có 2 format:
        // 1. {"13674357": {"charge": 0, "status": "Canceled", ...}}
        // 2. {"charge": 0, "status": "Canceled", ...}
        $statusData = $responseData[$providerOrderId] ?? (isset($responseData['status']) ? $responseData : null);

        if (!$statusData) {
            return;
        }

        $updateData = [];
        $providerStatus = $statusData['status'] ?? null;
        $refundTransaction = null;

        if (isset($statusData['start_count'])) {
            $updateData['start_count'] = $statusData['start_count'];
        }

        if (isset($statusData['remains'])) {
            $updateData['remains'] = $statusData['remains'];
        }

        if (!empty($providerStatus)) {
            $newStatus = $providerService->mapProviderStatus($providerStatus);
            $updateData['status'] = $newStatus;

            // Chỉ update completed_at cho các trạng thái kết thúc
            if (in_array($newStatus, [
                Order::STATUS_COMPLETED,
                Order::STATUS_PARTIAL,
                Order::STATUS_CANCELED,
                Order::STATUS_FAILED,
            ])) {
                $updateData['scan'] = 1;
            }
        }

        // Nếu status từ provider là "Canceled", hoàn tiền cho user
        if (strtolower($providerStatus) === 'canceled' && $order->status !== Order::STATUS_CANCELED) {
            $refundTransaction = $this->refundOrder($order);
        }

        if (!empty($updateData)) {
            $oldStatus = $order->status;
            $order->update($updateData);

            // Chỉ broadcast event khi status thay đổi
            if (isset($updateData['status']) && $oldStatus !== $updateData['status']) {
                Log::info('Broadcasting OrderStatusUpdated', [
                    'order_id' => $order->id,
                    'user_id' => $order->user_id,
                    'old_status' => $oldStatus,
                    'new_status' => $updateData['status'],
                    'has_refund' => $refundTransaction !== null,
                ]);
                // Broadcast 1 event duy nhất, kèm refund nếu có
                event(new OrderStatusUpdated($order, $refundTransaction));
            }
        }
    }

    /**
     * Hoàn tiền cho user khi order bị cancel từ provider
     *
     * @return Dongtien|null Transaction hoàn tiền nếu thành công
     */
    private function refundOrder(Order $order): ?Dongtien
    {
        if ($order->charge_amount <= 0 || !$order->user) {
            return null;
        }

        DB::beginTransaction();
        try {
            $refundAmount = $order->charge_amount;
            // Khóa user để đảm bảo cộng tiền chính xác
            $user = $order->user()->lockForUpdate()->first();
            if (!$user) {
                DB::rollBack();
                Log::warning('Refund skipped: user not found', [
                    'order_id' => $order->id,
                ]);
                return null;
            }

            // Tạo dòng tiền hoàn
            $transaction = Dongtien::createTransaction(
                $user,
                $refundAmount,
                Dongtien::TYPE_REFUND,
                "Hoàn tiền đơn hàng #{$order->id}",
                [
                    'order_id' => $order->id,
                    'payment_method' => 'system',
                ]
            );

            // Cập nhật refund_amount
            $order->update([
                'refund_amount' => $refundAmount,
            ]);

            // Log số dư mới
            $user->refresh();
            $this->info("    💰 Hoàn tiền {$refundAmount} cho order #{$order->id}, số dư mới: {$user->balance}");

            DB::commit();

            return $transaction;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error refunding order from provider cancel', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
