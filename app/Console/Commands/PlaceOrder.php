<?php

namespace App\Console\Commands;

use App\Helpers\RedisHelper;
use App\Models\Dongtien;
use App\Models\Order;
use App\Models\Service;
use App\Services\Providers\ProviderFactory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class PlaceOrder extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'order_place';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Xử lý đẩy đơn hàng pending lên provider';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🚀 Bắt đầu xử lý đơn hàng: ' . date('Y-m-d H:i:s'));

        $maxRamMb = 1024; // 1GB limit

        while (true) {
            // Kiểm tra RAM usage
            $currentRamMb = memory_get_usage(true) / 1024 / 1024;
            if ($currentRamMb > $maxRamMb) {
                $this->warn("⚠️ RAM vượt quá {$maxRamMb}MB, dừng xử lý.");
                break;
            }

            try {
                // Lấy order từ Redis queue (FIFO - rpop lấy từ cuối)
                $orderJson = Redis::connection(RedisHelper::REDIS_ORDER_WEB)->rpop(Order::KEY_ID_REDIS_ORDER);

                if (!$orderJson) {
                    // Không còn order trong queue, nghỉ 1 giây rồi tiếp tục
                    echo '.';
                    sleep(1);
                    continue;
                }

                $orderData = json_decode($orderJson, true);

                if (!$orderData || !isset($orderData['id'])) {
                    $this->warn("⚠️ Dữ liệu order không hợp lệ");
                    continue;
                }

                $this->processOrder($orderData);

            } catch (\Exception $e) {
                $this->error("❌ Lỗi: " . $e->getMessage());
                Log::error('PlaceOrder error', ['error' => $e->getMessage()]);
                sleep(1);
            }
        }

        return 0;
    }

    /**
     * Xử lý một đơn hàng
     */
    private function processOrder(array $orderData): void
    {
        $orderId = $orderData['id'];
        $this->line("  → Xử lý order #{$orderId}...");
 

        // Lấy order từ database với đầy đủ relationships
        $order = Order::with(['user', 'service.providerService.provider'])
            ->where('id', $orderId)
            ->where('status', Order::STATUS_PENDING)
            ->first();

        if (!$order) {
            $this->warn("    ⚠️ Order #{$orderId} không tồn tại hoặc đã được xử lý");
            return;
        }

        $service = $order->service;
        $provider = $service->providerService->provider ?? null;

        if (!$provider) {
            $this->updateOrderFailed($order, 'Provider không tồn tại');
            return;
        }

        // Kiểm tra provider có được hỗ trợ không
        if (!ProviderFactory::isSupported($provider->code)) {
            $this->updateOrderFailed($order, "Provider không được hỗ trợ: {$provider->code}");
            return;
        }

        try {
            // Tạo provider instance và gọi API
            $providerService = ProviderFactory::make($provider);

            $validated = [
                'link' => $order->link,
                'quantity' => $order->quantity,
            ];

            $response = $providerService->sendRequest($service, $validated);

            // Kiểm tra response
            if (!$providerService->isSuccessResponse($response)) {
                $errorMsg = is_array($response['data'])
                    ? json_encode($response['data'])
                    : ($response['body'] ?? 'Unknown error');

                $this->updateOrderFailed($order, $errorMsg);
                return;
            }

            // Cập nhật order thành công
            $providerOrderId = $providerService->getOrderIdFromResponse($response);

            $order->update([
                'provider_order_id' => $providerOrderId,
                'status' => Order::STATUS_PENDING,
            ]);

            $this->info("    ✅ Order #{$orderId} → Provider Order: {$providerOrderId}");

         

        } catch (\Exception $e) {
            $this->updateOrderFailed($order, $e->getMessage());

            Log::error('Error placing order', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Cập nhật order thất bại và hoàn tiền
     */
    private function updateOrderFailed(Order $order, string $errorMessage): void
    {
        $this->error("    ❌ Order #{$order->id}: {$errorMessage}");

        DB::beginTransaction();
        try {
            // Cập nhật order status
            $order->update([
                'status' => Order::STATUS_FAILED,
                'error_message' => $errorMessage,
            ]);

            // Hoàn tiền cho user
            $user = $order->user;
            if ($user && $order->charge_amount > 0) {
                $balanceBefore = $user->balance;
                $user->balance += $order->charge_amount;
                $user->save();

                // Tạo record dòng tiền hoàn
                Dongtien::create([
                    'balance_before' => $balanceBefore,
                    'amount' => $order->charge_amount,
                    'balance_after' => $user->balance,
                    'thoigian' => now(),
                    'noidung' => "Hoàn tiền đơn hàng #{$order->id} thất bại: {$errorMessage}",
                    'user_id' => $user->id,
                    'order_id' => $order->id,
                    'type' => Dongtien::TYPE_REFUND,
                    'payment_method' => 'system',
                ]);

                // Cập nhật refund amount
                $order->update([
                    'refund_amount' => $order->charge_amount,
                    'is_finalized' => true,
                    'final_charge' => 0,
                    'final_cost' => 0,
                    'final_profit' => 0,
                ]);

                $this->warn("    💰 Hoàn tiền {$order->charge_amount} cho user #{$user->id}");
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error refunding order', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
