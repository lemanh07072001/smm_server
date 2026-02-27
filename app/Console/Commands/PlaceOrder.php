<?php

namespace App\Console\Commands;

use App\Helpers\OrderActivityLogger;
use App\Helpers\RedisHelper;
use App\Helpers\TelegramHelper;
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

        // Khởi tạo activity logger
        $logger = OrderActivityLogger::for($orderId);
        $logger->processingStarted();

        // Lấy order từ database với đầy đủ relationships
        $order = Order::with(['user', 'service.providerService.provider'])
            ->where('id', $orderId)
            ->where('status', Order::STATUS_PENDING)
            ->first();

        if (!$order) {
            $this->warn("    ⚠️ Order #{$orderId} không tồn tại hoặc đã được xử lý");
            $logger->error('Order không tồn tại hoặc đã được xử lý');
            return;
        }


        $service = $order->service;
        $provider = $service->providerService->provider ?? null;

        if (!$provider) {
            $logger->orderFailed('Provider không tồn tại');
            $this->updateOrderFailed($order, 'Provider không tồn tại');
            return;
        }

        $logger->provider($provider->code);

        // Kiểm tra provider có được hỗ trợ không
        if (!ProviderFactory::isSupported($provider->code)) {
            $logger->orderFailed("Provider không được hỗ trợ: {$provider->code}");
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

            // Thêm livestream_duration nếu có
            if (!empty($order->livestream_duration)) {
                $validated['livestream_duration'] = $order->livestream_duration;
            }

            // Thêm comments nếu có
            if (!empty($order->comments)) {
                $validated['comments'] = $order->comments;
            }

            // Log request
            $startTime = microtime(true);
            $logger->providerRequest($providerService->buildApiUrl(), $providerService->buildAddOrderBody($service, $validated));

            $response = $providerService->sendRequest($service, $validated);

            // Log response
            $durationMs = (int) ((microtime(true) - $startTime) * 1000);
            $logger->providerResponse($response, $durationMs);

            // Kiểm tra response
            if (!$providerService->isSuccessResponse($response)) {
                $data = $response['data'] ?? [];
                $errorMsg = $data['error'] ?? $response['body'] ?? 'Unknown error';

                $logger->orderFailed($errorMsg);
                $this->updateOrderFailed($order, $errorMsg);
                return;
            }

            // Cập nhật order thành công
            $providerOrderId = $providerService->getOrderIdFromResponse($response);
            $logger->provider($provider->code, $providerOrderId);

            // Lấy status từ provider
            $logger->statusCheck();
            $statusResponse = $providerService->getOrderStatus($providerOrderId);

            $updateData = [
                'provider_order_id' => $providerOrderId,
                'status'            => Order::STATUS_IN_PROGRESS,
            ];

            // Parse status data từ response
            // API có thể trả về 2 format:
            // 1. {"13483494": {"charge": 22, "start_count": 0, "status": "Pending", ...}}
            // 2. {"charge": 22, "start_count": 0, "status": "Pending", ...}
            $responseData = $statusResponse['data'] ?? [];
            $statusData = $responseData[$providerOrderId] ?? (isset($responseData['status']) ? $responseData : null);

            if ($statusData) {
                $logger->statusResponse($statusData);

                $updateData['start_count'] = $statusData['start_count'] ?? null;
                $updateData['remains'] = $statusData['remains'] ?? null;

                // Map status từ provider sang system status
                if (!empty($statusData['status'])) {
                    $updateData['status'] = Order::mapProviderStatus($statusData['status']);
                }
            }

            $order->update($updateData);
            $logger->orderUpdated($updateData);

            // Log đẩy đơn thành công
            $logger->orderPlacedSuccess($providerOrderId, $updateData['status']);

            // Log hoàn thành xử lý
            $logger->processingCompleted();

            $this->info("    ✅ Order #{$orderId} → Provider Order: {$providerOrderId} | Status: {$updateData['status']}");
        } catch (\Exception $e) {
            $logger->error($e->getMessage(), $e);
            $this->updateOrderFailed($order, $e->getMessage());
        }
    }

    /**
     * Cập nhật order thất bại và hoàn tiền
     */
    private function updateOrderFailed(Order $order, string $errorMessage): void
    {
        $this->error("    ❌ Order #{$order->id}: {$errorMessage}");

        // Gửi thông báo Telegram
        $telegramMessage = "Order #{$order->id} thất bại\n"
            . "User: #{$order->user_id}\n"
            . "Link: {$order->link}\n"
            . "Lỗi: {$errorMessage}";
        TelegramHelper::sendNotifyErrorSystem($telegramMessage, '❌ Order Failed');

        DB::beginTransaction();
        try {
            // Cập nhật order status
            $order->update([
                'status' => Order::STATUS_FAILED,
                'error_message' => $errorMessage,
            ]);

            // Hoàn tiền cho user
            // $user = $order->user;
            // if ($user && $order->charge_amount > 0) {
            //     $balanceBefore = $user->balance;
            //     $user->balance += $order->charge_amount;
            //     $user->save();

            //     // Tạo record dòng tiền hoàn
            //     Dongtien::create([
            //         'balance_before' => $balanceBefore,
            //         'amount' => $order->charge_amount,
            //         'balance_after' => $user->balance,
            //         'thoigian' => now(),
            //         'noidung' => "Hoàn tiền đơn hàng #{$order->id} thất bại: {$errorMessage}",
            //         'user_id' => $user->id,
            //         'order_id' => $order->id,
            //         'type' => Dongtien::TYPE_REFUND,
            //         'payment_method' => 'system',
            //     ]);

            //     // Cập nhật refund amount
            //     $order->update([
            //         'refund_amount' => $order->charge_amount,
            //         'is_finalized' => true,
            //         'final_charge' => 0,
            //         'final_cost' => 0,
            //         'final_profit' => 0,
            //     ]);

            //     $this->warn("    💰 Hoàn tiền {$order->charge_amount} cho user #{$user->id}");
            // }

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
