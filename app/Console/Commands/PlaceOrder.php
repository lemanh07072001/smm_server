<?php

namespace App\Console\Commands;

use App\Helpers\OrderActivityLogger;
use App\Helpers\RedisHelper;
use App\Helpers\TelegramHelper;
use App\Models\Order;
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
    protected $signature = 'order_place
                            {action=add : Action to perform (add-priority|add|scan|status)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Xử lý đẩy đơn hàng lên provider (add-priority: priority=0 chạy liên tục, add: xử lý queue bình thường, scan: quét orders STATUS_PENDING retry<5 đẩy vào queue, status: kiểm tra trạng thái orders từ provider)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $action = $this->argument('action');

        return match ($action) {
            'add-priority' => $this->handleAddPriority(),
            'scan'         => $this->handleScan(),
            'status'       => $this->handleStatus(),
            default        => $this->error("Action không hợp lệ. Chỉ hỗ trợ: add-priority, add, scan, status"),
        };
    }

    /**
     * Xử lý orders is_priority = 0 (chạy liên tục trong while true)
     * Chạy bằng: php artisan order_place add-priority
     */
    protected function handleAddPriority()
    {
        $this->info('Bắt đầu xử lý orders is_priority = 0 (chạy liên tục)...');
        $this->info('Redis key: ' . Order::KEY_ID_REDIS_ORDER_PRIORITY_0);
        $processId = getmypid();
        $this->info("Process ID: {$processId}");

        while (true) {
            $orderJson = RedisHelper::rpop(Order::KEY_ID_REDIS_ORDER_PRIORITY_0);

            if ($orderJson) {
                $decoded = json_decode($orderJson, true);

                if ($decoded && isset($decoded['id'])) {
                    $orderId = $decoded['id'];

                    // Kiểm tra retry_after
                    if (isset($decoded['retry_after']) && time() < $decoded['retry_after']) {
                        RedisHelper::lpush(Order::KEY_ID_REDIS_ORDER_PRIORITY_0, $orderJson);
                        usleep(100000);
                        continue;
                    }

                    // Kiểm tra lock
                    $lockKey = "place_order_lock:order:{$orderId}";
                    $isLocked = RedisHelper::exists($lockKey);

                    if (!$isLocked) {
                        $this->warn("Order #{$orderId} chưa được lock, bỏ qua.");
                        usleep(100000);
                        continue;
                    }

                    try {
                        $order = Order::with(['user', 'service.providerService.provider'])
                            ->where('id', $orderId)
                            ->where('status', Order::STATUS_PENDING)
                            ->first();

                        if (!$order) {
                            $this->warn("Order #{$orderId} không tồn tại hoặc đã được xử lý.");
                            RedisHelper::del($lockKey);
                            usleep(100000);
                            continue;
                        }

                        $result = $this->callProviderApi($order);

                        if ($result['success']) {
                            $this->applySuccessUpdate($order, $result);
                            $this->info("Order #{$order->id}: OK -> Provider Order: {$result['provider_order_id']}");
                            Log::info("PlaceOrder ADD: #{$order->id} -> ID: {$result['provider_order_id']}");
                            RedisHelper::del($lockKey);
                        } else {
                            $currentRetry = $order->retry_count ?? 0;
                            $newRetry = $currentRetry + 1;

                            if ($newRetry >= Order::RETRY_COUNT) {
                                $this->applyFailedUpdate($order, $result['error'], $newRetry);
                                $this->error("Order #{$order->id}: FAILED sau " . Order::RETRY_COUNT . " lần -> STATUS_FAILED");
                                Log::error("PlaceOrder ADD: #{$order->id} -> FAILED -> STATUS_FAILED");
                                RedisHelper::del($lockKey);
                            } else {
                                $order->retry_count = $newRetry;
                                $order->save();

                                $decoded['retry_count'] = $newRetry;
                                $decoded['retry_after'] = time() + 2;
                                RedisHelper::lpush(Order::KEY_ID_REDIS_ORDER_PRIORITY_0, json_encode($decoded));

                                $this->warn("Order #{$order->id}: Lỗi lần {$newRetry}/" . Order::RETRY_COUNT . " - {$result['error']} -> Queue lại");
                                // KHÔNG release lock - giữ để retry
                            }
                        }
                    } catch (\Exception $e) {
                        $this->error("Order #{$orderId}: Exception - {$e->getMessage()}");
                        RedisHelper::del($lockKey);
                    }
                }
            }

            usleep(100000); // 100ms
        }
    }

   
    /**
     * Quét orders STATUS_PENDING, retry_count < RETRY_COUNT và đẩy vào queue
     * Chạy bằng: php artisan order_place scan
     */
    protected function handleScan()
    {
        $this->info('Bắt đầu quét orders STATUS_PENDING, retry_count < ' . Order::RETRY_COUNT . '...');
        $processId = getmypid();
        $this->info("Process ID: {$processId}");

        $orders = Order::where('status', Order::STATUS_PENDING)
            ->where('retry_count', '<', Order::RETRY_COUNT)
            ->orderBy('id', 'asc')
            ->cursor();

        $successCount = 0;
        $skippedCount = 0;

        foreach ($orders as $order) {
            $lockKey = "place_order_lock:order:{$order->id}";

            $lockAcquired = RedisHelper::acquireLock($lockKey, $processId, 300);

            if (!$lockAcquired) {
                $skippedCount++;
                continue;
            }

            try {
                $orderData = json_encode(['id' => $order->id]);

                if (isset($order->is_priority) && $order->is_priority == Order::PRIORITY[0]) {
                    RedisHelper::lpush(Order::KEY_ID_REDIS_ORDER_PRIORITY_0, $orderData);
                } else {
                    RedisHelper::rpush(Order::KEY_ID_REDIS_ORDER_PRIORITY_0, $orderData);
                }

                $successCount++;
            } catch (\Exception $e) {
                $this->error("Order #{$order->id}: Exception - {$e->getMessage()}");
                RedisHelper::del($lockKey);
            }
        }

        $this->info("Hoàn thành! Push {$successCount} orders vào queue, bỏ qua: {$skippedCount}");
        return 0;
    }

    /**
     * Kiểm tra trạng thái orders từ provider
     * Chạy bằng: php artisan order_place status
     */
    protected function handleStatus()
    {
        $this->info('Bắt đầu kiểm tra trạng thái orders...');

        $chunkSize = 500;
        $processed = 0;

        $statusFilter = [
            Order::STATUS_PENDING,

        ];

        $totalCount = Order::whereNotIn('status', $statusFilter)
            ->whereNotNull('provider_order_id')
            ->count();

        if ($totalCount === 0) {
            $this->info('Không có order nào cần kiểm tra trạng thái.');
            return 0;
        }

        $this->info("Tìm thấy {$totalCount} orders cần kiểm tra.");

        Order::with(['service.providerService.provider'])
            ->whereNotIn('status', $statusFilter)
            ->whereNotNull('provider_order_id')
            ->orderBy('id')
            ->chunk($chunkSize, function ($orders) use (&$processed, $totalCount) {
                // Group theo provider để gọi batch API
                $groupedOrders = $orders->groupBy(fn($o) => $o->service->providerService->provider->id ?? null);
                $groupedOrders->forget(null);

                foreach ($groupedOrders as $providerOrders) {
                    $this->processStatusBatch($providerOrders);
                }

                $processed += $orders->count();
                $this->info("Tiến độ: {$processed}/{$totalCount}");
            });

        $this->info('Hoàn thành!');
        return 0;
    }

    /**
     * Kiểm tra trạng thái batch orders từ cùng 1 provider
     * Gọi API 1 lần cho nhiều orders thay vì từng order
     */
    protected function processStatusBatch($orders): void
    {
        // Batch size tối đa mỗi lần gọi API (tránh request quá lớn)
        $apiBatchSize = 100;
        $sleepBetweenBatchMs = 500; // 500ms giữa các lần gọi API
        $circuitBreakerLimit = 10;  // Số batch thất bại liên tiếp → ngắt provider

        try {
            $firstOrder = $orders->first();
            $provider = $firstOrder->service->providerService->provider ?? null;

            if (!$provider || !ProviderFactory::isSupported($provider->code)) {
                $this->warn("Provider không hợp lệ hoặc không được hỗ trợ, bỏ qua {$orders->count()} orders.");
                return;
            }

            $providerService = ProviderFactory::make($provider);
            $this->info("Provider {$provider->code}: Kiểm tra {$orders->count()} orders...");

            // Index orders theo provider_order_id để tra cứu nhanh
            $orderMap = $orders->keyBy('provider_order_id');

            // Chia thành các mini-batch để gọi API
            $chunks = $orders->chunk($apiBatchSize);

            $consecutiveFails = 0; // đếm số batch thất bại liên tiếp

            foreach ($chunks as $chunk) {
                $providerOrderIds = $chunk->pluck('provider_order_id')->filter()->values()->all();

                if (empty($providerOrderIds)) {
                    continue;
                }

                // Circuit breaker: provider đang down → bỏ qua phần còn lại
                if ($consecutiveFails >= $circuitBreakerLimit) {
                    $remaining = $orders->count() - ($chunks->search($chunk) * $apiBatchSize);
                    $this->error("Provider {$provider->code}: {$consecutiveFails} batch thất bại liên tiếp → ngắt circuit breaker, bỏ qua ~{$remaining} orders còn lại.");
                    Log::error("PlaceOrder STATUS: circuit breaker triggered", ['provider' => $provider->code, 'consecutive_fails' => $consecutiveFails]);

                    $failedOrders = $consecutiveFails * $apiBatchSize;
                    TelegramHelper::sendNotifyErrorSystem(
                        "Circuit Breaker kich hoat!\n"
                        . "Provider: {$provider->code}\n"
                        . "Loi lien tiep: {$consecutiveFails} batch ({$failedOrders} orders)\n"
                        . "Bo qua: ~{$remaining} orders con lai\n"
                        . "Thoi gian: " . now()->format('Y-m-d H:i:s'),
                        'Provider Down'
                    );

                    break;
                }

                try {
                    // 1 lần gọi API cho toàn bộ batch, retry tối đa 2 lần nếu timeout/lỗi
                    $statusResponse = $this->callWithRetry(
                        fn() => $providerService->getOrderStatus($providerOrderIds),
                        maxRetries: 2,
                        backoffMs: 1000,
                        context: "Provider {$provider->code} batch status"
                    );

                    if ($statusResponse === null || !($statusResponse['success'] ?? false)) {
                        $body = $statusResponse['body'] ?? 'Unknown';
                        $this->warn("Provider {$provider->code}: Lỗi sau retry - {$body}. Bỏ qua " . count($providerOrderIds) . " orders.");
                        $consecutiveFails++;
                        usleep($sleepBetweenBatchMs * 1000);
                        continue;
                    }

                    $consecutiveFails = 0; // reset khi thành công

                    Log::info('STATUS raw response', [
                        'provider'          => $provider->code,
                        'order_ids'         => $providerOrderIds,
                        'raw_body'          => $statusResponse['body'] ?? null,
                        'raw_data'          => $statusResponse['data'] ?? null,
                        'request_order_id'  => $statusResponse['request_order_id'] ?? null,
                        'request_order_ids' => $statusResponse['request_order_ids'] ?? null,
                    ]);

                    $parsedData = $providerService->parseStatusResponse($statusResponse);

                    Log::info('STATUS parsed data', [
                        'provider'    => $provider->code,
                        'order_ids'   => $providerOrderIds,
                        'parsed_keys' => array_keys($parsedData),
                        'parsed_data' => $parsedData,
                    ]);

                    // Dùng bulk update thay vì update từng row
                    $bulkUpdates = [];

                    foreach ($providerOrderIds as $providerOrderId) {
                        $statusData = $parsedData[$providerOrderId] ?? null;
                        $order = $orderMap->get($providerOrderId);

                        if (!$order) {
                            continue;
                        }

                        if (!$statusData) {
                            $this->warn("Order #{$order->id} (provider: {$providerOrderId}): Không có status trong response.");
                            continue;
                        }

                        $updateData = [];

                        if (isset($statusData['start_count'])) {
                            $updateData['start_count'] = $statusData['start_count'];
                        }
                        if (isset($statusData['remains'])) {
                            $updateData['remains'] = $statusData['remains'];
                        }
                        if (!empty($statusData['status'])) {
                            // Dùng provider's mapProviderStatus để convert raw status (vd: "In progress" -> "in_progress")
                            $updateData['status'] = $providerService->mapProviderStatus($statusData['status']);
                        }

                        if (!empty($updateData)) {
                            $bulkUpdates[] = ['id' => $order->id, 'data' => $updateData, 'provider_status' => $statusData['status'] ?? '?'];
                        }
                    }

                    // Thực hiện bulk update theo từng nhóm status giống nhau
                    $this->applyBulkStatusUpdates($bulkUpdates);
                } catch (\Exception $e) {
                    $this->error("Provider {$provider->code} batch error: {$e->getMessage()}");
                    Log::error("PlaceOrder STATUS batch: provider={$provider->code} -> {$e->getMessage()}");
                }

                // Throttle giữa các lần gọi API để tránh rate limit
                usleep($sleepBetweenBatchMs * 1000);
            }
        } catch (\Exception $e) {
            $this->error("processStatusBatch: {$e->getMessage()}");
            Log::error("PlaceOrder STATUS batch: {$e->getMessage()}");
        }
    }

    /**
     * Gọi một callable với retry + exponential backoff
     * Trả về kết quả nếu thành công, null nếu hết retry
     */
    protected function callWithRetry(callable $fn, int $maxRetries, int $backoffMs, string $context = ''): ?array
    {
        $attempt = 0;

        while ($attempt <= $maxRetries) {
            try {
                $result = $fn();

                // Thành công
                if ($result['success'] ?? false) {
                    return $result;
                }

                // API trả về lỗi (không phải timeout) → không retry
                $this->warn("{$context}: API lỗi - " . ($result['body'] ?? 'Unknown'));
                return $result;

            } catch (\Exception $e) {
                $attempt++;
                $isTimeout = str_contains($e->getMessage(), 'timed out')
                    || str_contains($e->getMessage(), 'cURL error 28')
                    || $e instanceof \Illuminate\Http\Client\ConnectionException;

                if ($attempt > $maxRetries) {
                    $this->error("{$context}: Timeout/lỗi sau {$maxRetries} lần retry - {$e->getMessage()}");
                    Log::error("{$context} timeout sau retry", ['error' => $e->getMessage()]);
                    return null;
                }

                $waitMs = $backoffMs * $attempt; // 2s, 4s, 6s
                $type = $isTimeout ? 'Timeout' : 'Exception';
                $this->warn("{$context}: {$type} lần {$attempt}/{$maxRetries} - chờ {$waitMs}ms rồi retry...");
                usleep($waitMs * 1000);
            }
        }

        return null;
    }

    /**
     * Áp dụng bulk update cho danh sách orders
     * Group theo cùng updateData để giảm số lượng DB queries
     */
    protected function applyBulkStatusUpdates(array $bulkUpdates): void
    {
        if (empty($bulkUpdates)) {
            return;
        }

        // Group theo data giống nhau để update 1 query nhiều rows
        $grouped = [];
        foreach ($bulkUpdates as $item) {
            $key = md5(json_encode($item['data']));
            $grouped[$key]['data'] = $item['data'];
            $grouped[$key]['ids'][] = $item['id'];
            $grouped[$key]['provider_status'] = $item['provider_status'];
        }

        foreach ($grouped as $group) {
            try {
                Order::whereIn('id', $group['ids'])->update($group['data']);

                $status = $group['data']['status'] ?? '?';
                $ids = implode(',', $group['ids']);
                $this->info("  Updated " . count($group['ids']) . " orders -> {$group['provider_status']} -> {$status} (ids: {$ids})");
                Log::info("PlaceOrder STATUS bulk: " . count($group['ids']) . " orders -> {$status}", ['ids' => $group['ids']]);
            } catch (\Exception $e) {
                $this->error("Bulk update error: {$e->getMessage()}");
                Log::error("PlaceOrder STATUS bulk update: {$e->getMessage()}", ['ids' => $group['ids']]);
            }
        }
    }

    /**
     * Gọi Provider API và trả về kết quả
     */
    protected function callProviderApi(Order $order): array
    {
        try {
            $service = $order->service;
            $provider = $service->providerService->provider ?? null;

            if (!$provider) {
                return ['success' => false, 'error' => 'Provider không tồn tại'];
            }

            if (!ProviderFactory::isSupported($provider->code)) {
                return ['success' => false, 'error' => "Provider không được hỗ trợ: {$provider->code}"];
            }

            $providerService = ProviderFactory::make($provider);

            $validated = [
                'link'     => $order->link,
                'quantity' => $order->quantity,
            ];

            if (!empty($order->livestream_duration)) {
                $validated['livestream_duration'] = $order->livestream_duration;
            }

            if (!empty($order->comments)) {
                $validated['comments'] = $order->comments;
            }

            $response = $providerService->sendRequest($service, $validated);

            if (!$providerService->isSuccessResponse($response)) {
                $data = $response['data'] ?? [];
                $errorMsg = $data['error'] ?? $response['body'] ?? 'Unknown error';
                return ['success' => false, 'error' => $errorMsg];
            }

            $providerOrderId = $providerService->getOrderIdFromResponse($response);

            // Lấy status ngay sau khi tạo thành công
            $statusData = $this->fetchProviderStatus($providerService, $providerOrderId);

            return [
                'success'           => true,
                'provider_order_id' => $providerOrderId,
                'status_data'       => $statusData,
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Lấy status từ provider sau khi tạo order
     */
    protected function fetchProviderStatus($providerService, $providerOrderId): ?array
    {
        try {
            if ($providerOrderId === null) {
                return null;
            }

            $statusResponse = $providerService->getOrderStatus($providerOrderId);
            $responseData = $statusResponse['data'] ?? [];
            return $responseData[$providerOrderId] ?? (isset($responseData['status']) ? $responseData : null);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Cập nhật order khi thành công
     */
    protected function applySuccessUpdate(Order $order, array $result): void
    {
        $providerOrderId = $result['provider_order_id'];
        $statusData = $result['status_data'] ?? null;

        $updateData = [
            'provider_order_id' => $providerOrderId,
            'status'            => Order::STATUS_IN_PROGRESS,
        ];

        if ($statusData) {
            $updateData['start_count'] = $statusData['start_count'] ?? null;
            $updateData['remains']     = $statusData['remains'] ?? null;

            if (!empty($statusData['status'])) {
                $updateData['status'] = Order::mapProviderStatus($statusData['status']);
            }
        }

        $order->update($updateData);
    }

    /**
     * Cập nhật order khi thất bại hoàn toàn
     */
    protected function applyFailedUpdate(Order $order, string $errorMessage, int $retryCount): void
    {
        $this->error("Order #{$order->id}: {$errorMessage}");

        $telegramMessage = "Order #{$order->id} thất bại\n"
            . "User: #{$order->user_id}\n"
            . "Link: {$order->link}\n"
            . "Lỗi: {$errorMessage}";
        TelegramHelper::sendNotifyErrorSystem($telegramMessage, 'Order Failed');

        DB::beginTransaction();
        try {
            $order->update([
                'status'        => Order::STATUS_FAILED,
                'error_message' => $errorMessage,
                'retry_count'   => $retryCount,
            ]);

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error updating failed order', [
                'order_id' => $order->id,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    /**
     * Xử lý một đơn hàng từ queue thông thường (dùng cho handleAdd)
     */
    private function processOrder(array $orderData): void
    {
        $orderId = $orderData['id'];
        $this->line("  -> Xử lý order #{$orderId}...");

        $logger = OrderActivityLogger::for($orderId);
        $logger->processingStarted();

        $order = Order::with(['user', 'service.providerService.provider'])
            ->where('id', $orderId)
            ->where('status', Order::STATUS_PENDING)
            ->first();

        if (!$order) {
            $this->warn("    Order #{$orderId} không tồn tại hoặc đã được xử lý");
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

        if (!ProviderFactory::isSupported($provider->code)) {
            $logger->orderFailed("Provider không được hỗ trợ: {$provider->code}");
            $this->updateOrderFailed($order, "Provider không được hỗ trợ: {$provider->code}");
            return;
        }

        try {
            $providerService = ProviderFactory::make($provider);

            $validated = [
                'link'     => $order->link,
                'quantity' => $order->quantity,
            ];

            if (!empty($order->livestream_duration)) {
                $validated['livestream_duration'] = $order->livestream_duration;
            }

            if (!empty($order->comments)) {
                $validated['comments'] = $order->comments;
            }

            $startTime = microtime(true);
            $logger->providerRequest($providerService->buildApiUrl(), $providerService->buildAddOrderBody($service, $validated));

            $response = $providerService->sendRequest($service, $validated);

            $durationMs = (int) ((microtime(true) - $startTime) * 1000);
            $logger->providerResponse($response, $durationMs);

            if (!$providerService->isSuccessResponse($response)) {
                $data = $response['data'] ?? [];
                $errorMsg = $data['error'] ?? $response['body'] ?? 'Unknown error';
                $logger->orderFailed($errorMsg);
                $this->updateOrderFailed($order, $errorMsg);
                return;
            }

            $providerOrderId = $providerService->getOrderIdFromResponse($response);

            if ($providerOrderId === null) {
                $logger->orderFailed('Provider không trả về order ID');
                $this->updateOrderFailed($order, 'Provider không trả về order ID');
                return;
            }

            $logger->provider($provider->code, $providerOrderId);

            $logger->statusCheck();
            $statusResponse = $providerService->getOrderStatus($providerOrderId);

            $updateData = [
                'provider_order_id' => $providerOrderId,
                'status'            => Order::STATUS_IN_PROGRESS,
            ];

            $responseData = $statusResponse['data'] ?? [];
            $statusData = $responseData[$providerOrderId] ?? (isset($responseData['status']) ? $responseData : null);

            if ($statusData) {
                $logger->statusResponse($statusData);
                $updateData['start_count'] = $statusData['start_count'] ?? null;
                $updateData['remains']     = $statusData['remains'] ?? null;

                if (!empty($statusData['status'])) {
                    $updateData['status'] = Order::mapProviderStatus($statusData['status']);
                }
            }

            $order->update($updateData);
            $logger->orderUpdated($updateData);
            $logger->orderPlacedSuccess($providerOrderId, $updateData['status']);
            $logger->processingCompleted();

            $this->info("    Order #{$orderId} -> Provider Order: {$providerOrderId} | Status: {$updateData['status']}");
        } catch (\Exception $e) {
            $logger->error($e->getMessage(), $e);
            $this->updateOrderFailed($order, $e->getMessage());
        }
    }

    /**
     * Cập nhật order thất bại và gửi thông báo (dùng cho handleAdd)
     */
    private function updateOrderFailed(Order $order, string $errorMessage): void
    {
        $this->error("    Order #{$order->id}: {$errorMessage}");

        $telegramMessage = "Order #{$order->id} thất bại\n"
            . "User: #{$order->user_id}\n"
            . "Link: {$order->link}\n"
            . "Lỗi: {$errorMessage}";
        TelegramHelper::sendNotifyErrorSystem($telegramMessage, 'Order Failed');

        DB::beginTransaction();
        try {
            $order->update([
                'status'        => Order::STATUS_FAILED,
                'error_message' => $errorMessage,
            ]);

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error refunding order', [
                'order_id' => $order->id,
                'error'    => $e->getMessage(),
            ]);
        }
    }
}
