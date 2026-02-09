<?php

namespace App\Console\Commands;

use App\Events\OrderStatusUpdated;
use App\Models\AffiliateCommission;
use App\Models\Order;
use App\Models\Dongtien;
use App\Models\User;
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
            $this->info('No orders to check');
            return 0;
        }

        $this->info("Found {$orders->count()} orders to check");

        foreach ($orders as $order) {
            $this->processProviderOrders($order);
        }

        $this->info('Done checking orders');
        return 0;
    }

    private function processProviderOrders(Order $order): void
    {
        $provider = $order->service->providerService->provider;

        if (!ProviderFactory::isSupported($provider->code)) {
            $this->warn("Provider {$provider->code} not supported for order #{$order->id}");
            return;
        }

        $providerService = ProviderFactory::make($provider);

        try {
            $this->info("Checking order #{$order->id} (Provider: {$provider->code}, Order ID: {$order->provider_order_id})");

            $statusResponse = $providerService->getOrderStatus($order->provider_order_id);

            if ($statusResponse['success']) {
                $this->updateOrder($order, $statusResponse, $providerService);
            } else {
                $this->error("  Failed to get status: " . ($statusResponse['body'] ?? 'Unknown error'));
            }
        } catch (\Exception $e) {
            Log::error('CheckOrderStatus error', [
                'provider' => $provider->code,
                'order_id' => $order->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->error("  Error: {$e->getMessage()}");
        }
    }

    private function updateOrder(Order $order, $statusResponse, $providerService): void
    {
        $providerOrderId = $order->provider_order_id;

        // Sử dụng parseStatusResponse để parse response theo format của từng provider
        $parsedData = $providerService->parseStatusResponse($statusResponse);

        // Lấy data của order hiện tại
        $statusData = $parsedData[$providerOrderId] ?? null;

        if (!$statusData) {
            Log::warning('Status data not found for order', [
                'order_id' => $order->id,
                'provider_order_id' => $providerOrderId,
                'parsed_data' => $parsedData,
            ]);
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

            // Nếu status từ provider là "Canceled", hoàn tiền cho user
            if (strtolower($providerStatus) === 'canceled' && $order->status !== Order::STATUS_CANCELED) {
                $refundTransaction = $this->refundOrder($order);
            }
        }

        if (!empty($updateData)) {
            $oldStatus = $order->status;
            $order->update($updateData);

            // Tính hoa hồng affiliate khi order completed
            if (isset($updateData['status']) && $updateData['status'] === Order::STATUS_COMPLETED && $oldStatus !== Order::STATUS_COMPLETED) {
                $this->processAffiliateCommission($order);
            }

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
     * Tính hoa hồng affiliate khi order hoàn thành
     */
    private function processAffiliateCommission(Order $order): void
    {
        try {
            $user = $order->user;
            if (!$user || !$user->referred_by) {
                return;
            }

            // Kiểm tra đã tính commission cho order này chưa
            if (AffiliateCommission::where('order_id', $order->id)->exists()) {
                return;
            }

            $referrer = User::find($user->referred_by);
            if (!$referrer) {
                return;
            }

            $commissionRate = 10.00; // 10%
            $orderAmount = (float) $order->charge_amount;
            $commissionAmount = round($orderAmount * $commissionRate / 100, 2);

            if ($commissionAmount <= 0) {
                return;
            }

            DB::beginTransaction();

            AffiliateCommission::create([
                'user_id' => $referrer->id,
                'order_id' => $order->id,
                'referred_user_id' => $user->id,
                'order_amount' => $orderAmount,
                'commission_rate' => $commissionRate,
                'commission_amount' => $commissionAmount,
            ]);

            $referrer->increment('affiliate_balance', $commissionAmount);

            DB::commit();

            $this->info("    Affiliate: +{$commissionAmount} cho user #{$referrer->id} từ order #{$order->id}");
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Affiliate commission error', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
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
