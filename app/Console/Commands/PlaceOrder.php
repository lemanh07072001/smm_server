<?php

namespace App\Console\Commands;

use App\Helpers\OrderActivityLogger;
use App\Helpers\RedisHelper;
use App\Helpers\TelegramHelper;
use App\Models\Dongtien;
use App\Models\Order;
use App\Models\User;
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
                            {action=add : Action to perform (add-priority|add|scan|status|refund-check)}';

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
            'refund-check' => $this->handleRefundCheck(),
            default        => $this->error("Action không hợp lệ. Chỉ hỗ trợ: add-priority, add, scan, status, refund-check"),
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
        $this->info('Process ID: ' . getmypid());

        $startTime = time();
        $timeoutSeconds = 600; // tự thoát sau 10 phút, supervisor/schedule restart lại

        while (true) {
            if ((time() - $startTime) >= $timeoutSeconds) {
                $this->info('Đã chạy đủ ' . $timeoutSeconds . 's, thoát để restart.');
                break;
            }

            $orderJson = RedisHelper::rpop(Order::KEY_ID_REDIS_ORDER_PRIORITY_0);

            if (!$orderJson) {
                usleep(100000); // 100ms
                continue;
            }

            $decoded = json_decode($orderJson, true);

            if (!$decoded || !isset($decoded['id'])) {
                usleep(100000);
                continue;
            }

            $orderId = $decoded['id'];

            // Kiểm tra retry_after - push lại cuối queue nếu chưa đến lúc retry
            if (isset($decoded['retry_after']) && time() < $decoded['retry_after']) {
                RedisHelper::rpush(Order::KEY_ID_REDIS_ORDER_PRIORITY_0, $orderJson);
                usleep(100000);
                continue;
            }

            // Atomic lock để tránh race condition khi chạy nhiều process song song.
            // Ai set được NX thì mới xử lý, không cần lock riêng từ scan nữa.
            $lockKey = "place_order_lock:order:{$orderId}";
            $locked  = RedisHelper::acquireLock($lockKey, getmypid(), 120);

            if (!$locked) {
                // Process khác đang xử lý order này
                usleep(100000);
                continue;
            }

            try {
                $order = Order::with(['user', 'service.providerService.provider'])
                    ->where('id', $orderId)
                    ->where('status', Order::STATUS_PENDING)
                    ->whereNull('provider_order_id')
                    ->first();

                if (!$order) {
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
                } elseif (!empty($result['skip_retry'])) {
                    if (!empty($result['keep_pending'])) {
                        // Provider bị tắt tạm → giữ pending, scan sẽ push lại sau
                        $this->warn("Order #{$order->id}: Provider bị tắt → giữ pending.");
                    } else {
                        // Lỗi từ service provider (maintenance, invalid,...) → fail ngay, hoàn tiền
                        $this->applyFailedUpdate($order, $result['error'], $order->retry_count ?? 0);
                        $this->error("Order #{$order->id}: FAILED (skip retry) -> {$result['error']}");
                        Log::error("PlaceOrder ADD: #{$order->id} -> FAILED (skip retry) -> {$result['error']}");
                    }
                    RedisHelper::del($lockKey);
                } else {
                    $currentRetry = $order->retry_count ?? 0;
                    $newRetry     = $currentRetry + 1;

                    if ($newRetry >= Order::RETRY_COUNT) {
                        $this->applyFailedUpdate($order, $result['error'], $newRetry);
                        $this->error("Order #{$order->id}: FAILED sau " . Order::RETRY_COUNT . " lần -> STATUS_FAILED");
                        Log::error("PlaceOrder ADD: #{$order->id} -> FAILED -> STATUS_FAILED");
                        RedisHelper::del($lockKey);
                    } else {
                        $order->retry_count    = $newRetry;
                        $order->internal_note  = "[Retry {$newRetry}/" . Order::RETRY_COUNT . "] " . ($result['error'] ?? 'Unknown error');
                        $order->save();

                        $decoded['retry_count'] = $newRetry;
                        $decoded['retry_after'] = time() + 2;
                        // Đẩy về cuối queue để không block các order khác
                        RedisHelper::rpush(Order::KEY_ID_REDIS_ORDER_PRIORITY_0, json_encode($decoded));

                        $this->warn("Order #{$order->id}: Lỗi lần {$newRetry}/" . Order::RETRY_COUNT . " - {$result['error']} -> Queue lại");
                        RedisHelper::del($lockKey); // release để retry lần sau acquire lại
                    }
                }
            } catch (\Exception $e) {
                $this->error("Order #{$orderId}: Exception - {$e->getMessage()}");
                RedisHelper::del($lockKey);
            }

            usleep(100000); // 100ms
        }
    }

   
    /**
     * Fallback/recovery: quét orders STATUS_PENDING bị sót (chưa được push bởi Controller)
     * Chạy bằng: php artisan order_place scan
     *
     * Không cần acquire lock ở đây - handleAddPriority sẽ atomic lock khi pop ra.
     * scan chỉ đảm bảo order được có mặt trong queue; duplicate trong queue không sao
     * vì handleAddPriority kiểm tra DB (status=pending, provider_order_id IS NULL).
     */
    protected function handleScan()
    {
        return $this->runHandleScan();
    }

    protected function runHandleScan()
    {
        $this->info('[' . now()->format('H:i:s') . '] Bắt đầu quét orders STATUS_PENDING...');

        // priority=0 được Controller push ngay → cutoff 2 phút để tránh race
        // order thường chỉ do scan push → không cần cutoff nhưng giữ chung cho đơn giản
        $cutoff = now()->subMinutes(2);

        // Chỉ SELECT 2 cột cần thiết, không load full model → giảm memory/bandwidth
        $orders = Order::select(['id', 'is_priority'])
            ->where('status', Order::STATUS_PENDING)
            ->whereNull('provider_order_id')
            ->where(function ($q) {
                $q->whereNull('retry_count')
                  ->orWhere('retry_count', '<', Order::RETRY_COUNT);
            })
            ->where('created_at', '<=', $cutoff)
            ->orderBy('id', 'asc')
            ->cursor();

        $pushed  = 0;
        $skipped = 0;

        $flushChunk = function (array $chunk) use (&$pushed, &$skipped) {
            $count = count($chunk); // array_values() đã làm ở ngoài

            // mget 2 nhóm key trong 1 pipeline (EXISTS trả về 0/1, mget nhanh hơn với keys ngắn)
            // Dùng EXISTS cho lock key (thường rất ít key tồn tại) và scan_queued (thường tồn tại nhiều)
            $results = Redis::pipeline(function ($pipe) use ($chunk) {
                foreach ($chunk as $o) { $pipe->exists('place_order_lock:order:' . $o->id); }
                foreach ($chunk as $o) { $pipe->exists('scan_queued:order:' . $o->id); }
            });

            $lockResults   = array_slice($results, 0, $count);
            $queuedResults = array_slice($results, $count, $count);

            $priorityPush = [];
            $normalPush   = [];
            $scanQueuedKeys = [];

            foreach ($chunk as $i => $order) {
                if ($lockResults[$i] || $queuedResults[$i]) {
                    $skipped++;
                    continue;
                }
                $orderData = json_encode(['id' => $order->id]);
                if ($order->is_priority == Order::PRIORITY[0]) {
                    $priorityPush[] = $orderData;
                } else {
                    $normalPush[] = $orderData;
                }
                $scanQueuedKeys[] = 'scan_queued:order:' . $order->id;
                $pushed++;
            }

            if (empty($scanQueuedKeys)) return;

            // 1 pipeline: push queue + setex scan_queued (TTL 5 phút) cùng lúc
            Redis::pipeline(function ($pipe) use ($priorityPush, $normalPush, $scanQueuedKeys) {
                if (!empty($priorityPush)) {
                    $pipe->lpush(Order::KEY_ID_REDIS_ORDER_PRIORITY_0, ...$priorityPush);
                }
                if (!empty($normalPush)) {
                    $pipe->rpush(Order::KEY_ID_REDIS_ORDER_PRIORITY_0, ...$normalPush);
                }
                foreach ($scanQueuedKeys as $key) {
                    $pipe->setex($key, 300, 1);
                }
            });
        };

        $chunkBuffer = [];
        $chunkSize   = 1000; // Tăng lên 1000: 2 pipeline x 1000 EXISTS/chunk, ít round-trips hơn

        foreach ($orders as $order) {
            $chunkBuffer[] = $order;
            if (count($chunkBuffer) >= $chunkSize) {
                $flushChunk(array_values($chunkBuffer));
                $chunkBuffer = [];
            }
        }

        if (!empty($chunkBuffer)) {
            $flushChunk(array_values($chunkBuffer));
        }

        $this->info('[' . now()->format('H:i:s') . "] Hoàn thành! Pushed: {$pushed}, Skipped (đang xử lý): {$skipped}");
    }

    /**
     * Kiểm tra trạng thái orders từ provider
     * Chạy bằng: php artisan order_place status
     */
    protected function handleStatus()
    {
        $lockKey = 'place_order:status_running';
        $locked  = RedisHelper::acquireLock($lockKey, getmypid(), 120);

        if (!$locked) {
            $this->warn('handleStatus đang chạy ở process khác, bỏ qua lần này.');
            return 0;
        }

        try {
            return $this->runHandleStatus();
        } finally {
            RedisHelper::del($lockKey);
        }
    }

    protected function runHandleStatus()
    {
        $this->info('Bắt đầu kiểm tra trạng thái orders...');

        $chunkSize = 500;
        $processed = 0;

        // Chỉ check orders đang active (đã gửi lên provider, chưa hoàn thành)
        $activeStatuses = [
            Order::STATUS_IN_PROGRESS,
            Order::STATUS_PENDING,
        ];

        $totalCount = Order::whereIn('status', $activeStatuses)
            ->whereNotNull('provider_order_id')
            ->count();

        if ($totalCount === 0) {
            $this->info('Không có order nào cần kiểm tra trạng thái.');
            return 0;
        }

        $this->info("Tìm thấy {$totalCount} orders cần kiểm tra.");

        Order::with(['service.providerService.provider'])
            ->whereIn('status', $activeStatuses)
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
        $apiBatchSize        = 100;
        $minSleepMs          = 100;  // tối thiểu 100ms (thay vì cứng 500ms)
        $maxSleepMs          = 2000; // tối đa 2s khi provider chậm
        $circuitBreakerLimit = 10;   // Số batch thất bại liên tiếp → ngắt provider

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

                $batchStartTime = microtime(true);

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
                        $errorMsg = '';
                        if (is_string($body)) {
                            $decoded = json_decode($body, true);
                            $errorMsg = $decoded['error'] ?? $body;
                        }
                        $this->warn("Provider {$provider->code}: Lỗi sau retry - {$body}. Bỏ qua " . count($providerOrderIds) . " orders.");

                        // Nếu lỗi do số dư tài khoản provider không đủ → mark partial + ghi error_message + log DB
                        if ($errorMsg && (
                            str_contains($errorMsg, 'Số dư') ||
                            str_contains(strtolower($errorMsg), 'balance') ||
                            str_contains(strtolower($errorMsg), 'insufficient')
                        )) {
                            foreach ($providerOrderIds as $providerOrderId) {
                                $order = $orderMap->get($providerOrderId);
                                if (!$order) {
                                    continue;
                                }
                                Order::where('id', $order->id)->update([
                                    'status'        => Order::STATUS_PARTIAL,
                                    'error_message' => "Provider hết số dư, không thể kiểm tra trạng thái: {$errorMsg}",
                                ]);
                                OrderActivityLogger::for($order->id)
                                    ->provider($provider->code, $providerOrderId)
                                    ->error("Provider hết số dư, không thể kiểm tra trạng thái: {$errorMsg}");
                                $this->warn("  → Order #{$order->id} (provider: {$providerOrderId}): đổi sang partial do provider hết số dư.");
                            }
                        }

                        $consecutiveFails++;
                    } else {
                        $consecutiveFails = 0; // reset khi thành công

                        $parsedData = $providerService->parseStatusResponse($statusResponse);

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
                                $mappedStatus = $providerService->mapProviderStatus($statusData['status']);
                                $updateData['status'] = $mappedStatus;
                                $this->line("  Order #{$order->id} | provider_id: {$providerOrderId} | raw: '{$statusData['status']}' → mapped: '{$mappedStatus}'");
                                // Chỉ ghi log khi status thay đổi
                                if ($mappedStatus !== $order->status) {
                                    OrderActivityLogger::for($order->id)
                                        ->provider($provider->code, $providerOrderId)
                                        ->statusResponse($statusData);
                                }
                            }
                            // Lỗi per-order từ provider (vd: "Incorrect order id")
                            if (!empty($statusData['error'])) {
                                $updateData['status'] = Order::STATUS_FAILED;
                                $this->warn("  Order #{$order->id} | provider_id: {$providerOrderId} | error: '{$statusData['error']}'");
                                OrderActivityLogger::for($order->id)
                                    ->provider($provider->code, $providerOrderId)
                                    ->error($statusData['error']);
                            }

                            if (!empty($updateData)) {
                                $bulkUpdates[] = ['id' => $order->id, 'data' => $updateData, 'provider_status' => $statusData['status'] ?? '?'];
                            }
                        }

                        // Thực hiện bulk update theo từng nhóm status giống nhau
                        $this->applyBulkStatusUpdates($bulkUpdates);
                    }
                } catch (\Exception $e) {
                    $this->error("Provider {$provider->code} batch error: {$e->getMessage()}");
                    Log::error("PlaceOrder STATUS batch: provider={$provider->code} -> {$e->getMessage()}");
                }

                // Adaptive throttle: đo thời gian thực tế của batch, chỉ sleep phần còn thiếu
                // Mục tiêu tối thiểu $minSleepMs giữa 2 batch để tránh rate limit
                $elapsedMs = (microtime(true) - $batchStartTime) * 1000;
                $remainMs  = $minSleepMs - (int) $elapsedMs;
                if ($remainMs > 0) {
                    usleep(min($remainMs, $maxSleepMs) * 1000);
                }
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
                $newStatus = $group['data']['status'] ?? null;
                $isFailed  = $newStatus === Order::STATUS_FAILED;
                $isPartial = $newStatus === Order::STATUS_PARTIAL;

                // Load orders nếu cần tính hoàn tiền
                $ordersToProcess = ($isFailed || $isPartial)
                    ? Order::whereIn('id', $group['ids'])->get()
                    : collect();

                $isCompleted = $newStatus === Order::STATUS_COMPLETED;

                if ($isFailed) {
                    Order::whereIn('id', $group['ids'])->update(
                        array_merge($group['data'], ['refund_amount' => DB::raw('charge_amount')])
                    );
                } elseif ($isCompleted) {
                    // Chỉ lấy các order chưa có completed_at (lần đầu hoàn thành)
                    $justCompletedIds = Order::whereIn('id', $group['ids'])
                        ->whereNull('completed_at')
                        ->pluck('id')
                        ->all();

                    Order::whereIn('id', $group['ids'])
                        ->whereNull('completed_at')
                        ->update(array_merge($group['data'], ['completed_at' => now()]));

                    // Dispatch job thống kê chỉ cho các order vừa mới hoàn thành lần đầu
                    if (!empty($justCompletedIds)) {
                        Order::whereIn('id', $justCompletedIds)
                            ->whereBetween('quantity', [1, 1000])
                            ->each(fn($o) => \App\Jobs\CalculateOrderCompletionStat::dispatch($o));
                    }
                } else {
                    Order::whereIn('id', $group['ids'])->update($group['data']);
                }

                // Tính refund cho từng order, group theo user_id để gộp vào 1 transaction/user
                // Thay vì N transactions riêng lẻ → M transactions (M = số user duy nhất)
                $refundsByUser = []; // [user_id => [{order_id, amount, note}]]

                foreach ($ordersToProcess as $processOrder) {
                    $remains      = (int) ($group['data']['remains'] ?? $processOrder->remains ?? 0);
                    $quantity     = (int) $processOrder->quantity;
                    $chargeAmount = (float) $processOrder->charge_amount;

                    if ($isFailed) {
                        $refundAmount = $chargeAmount;
                        $note = "Hoàn tiền đơn hàng #{$processOrder->id} thất bại";
                    } elseif ($isPartial) {
                        $refundAmount = ($quantity > 0 && $remains > 0 && $chargeAmount > 0)
                            ? round(($remains / $quantity) * $chargeAmount, 2)
                            : 0;
                        $note = "Hoàn tiền một phần đơn hàng #{$processOrder->id} (còn lại: {$remains}/{$quantity})";
                    } else {
                        $refundAmount = 0;
                        $note = '';
                    }

                    if ($refundAmount > 0) {
                        $refundsByUser[$processOrder->user_id][] = [
                            'order_id' => $processOrder->id,
                            'amount'   => $refundAmount,
                            'note'     => $note,
                        ];
                    }
                }

                // 1 transaction per user, gộp toàn bộ đơn của user đó
                foreach ($refundsByUser as $userId => $refunds) {
                    DB::beginTransaction();
                    try {
                        $user = User::lockForUpdate()->find($userId);
                        if (!$user) {
                            DB::rollBack();
                            continue;
                        }

                        foreach ($refunds as $refund) {
                            Order::where('id', $refund['order_id'])->update(['refund_amount' => $refund['amount']]);
                            Dongtien::createTransaction(
                                $user,
                                $refund['amount'],
                                Dongtien::TYPE_REFUND,
                                $refund['note'],
                                ['order_id' => $refund['order_id']]
                            );
                            OrderActivityLogger::for($refund['order_id'])->user($userId)->refund($refund['amount']);
                            $this->info("  Order #{$refund['order_id']}: Hoàn {$refund['amount']} cho user #{$userId} ({$newStatus})");
                        }

                        DB::commit();
                    } catch (\Exception $e) {
                        DB::rollBack();
                        Log::error('Error refunding orders (bulk status)', [
                            'user_id'   => $userId,
                            'order_ids' => array_column($refunds, 'order_id'),
                            'status'    => $newStatus,
                            'error'     => $e->getMessage(),
                        ]);
                    }
                }

                $ids = implode(',', $group['ids']);
                $this->info("  Updated " . count($group['ids']) . " orders -> {$group['provider_status']} -> {$newStatus} (ids: {$ids})");
                Log::info("PlaceOrder STATUS bulk: " . count($group['ids']) . " orders -> {$newStatus}", ['ids' => $group['ids']]);
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
        $logger = OrderActivityLogger::for($order->id)->user($order->user_id);

        try {
            $service  = $order->service;
            $provider = $service->providerService->provider ?? null;

            if (!$provider) {
                $logger->error('Provider không tồn tại');
                return ['success' => false, 'error' => 'Provider không tồn tại'];
            }

            if (!ProviderFactory::isSupported($provider->code)) {
                $logger->error("Provider không được hỗ trợ: {$provider->code}");
                return ['success' => false, 'error' => "Provider không được hỗ trợ: {$provider->code}"];
            }

            if (!$provider->is_active) {
                $logger->error("Provider [{$provider->code}] đang bị tắt bởi Super Admin.");
                return ['success' => false, 'error' => "Provider [{$provider->code}] đang bị tắt bởi Super Admin.", 'skip_retry' => true, 'keep_pending' => true];
            }

            $logger->provider($provider->code)->processingStarted();

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

            $logger->provider($provider->code)->providerRequest(
                $provider->api_url . '/add',
                array_merge($validated, ['service' => $service->providerService->provider_service_code ?? ''])
            );

            $startTime  = microtime(true);
            $response   = $providerService->sendRequest($service, $validated);
            $durationMs = (int) ((microtime(true) - $startTime) * 1000);

            $logger->provider($provider->code)->providerResponse($response, $durationMs);

            if (!$providerService->isSuccessResponse($response)) {
                $data     = $response['data'] ?? [];
                $errorMsg = $data['error'] ?? $response['body'] ?? 'Unknown error';
                $logger->provider($provider->code)->orderFailed($errorMsg);

                // Một số lỗi retry cũng vô nghĩa → skip retry, fail ngay
                $skipRetryKeywords = ['maintenance', 'invalid service', 'service not found', 'service is disabled'];
                $isSkipRetry = collect($skipRetryKeywords)->contains(
                    fn($kw) => str_contains(strtolower($errorMsg), $kw)
                );

                return ['success' => false, 'error' => $errorMsg, 'skip_retry' => $isSkipRetry];
            }

            $providerOrderId = $providerService->getOrderIdFromResponse($response);

            $logger->provider($provider->code, $providerOrderId)->orderPlacedSuccess(
                $providerOrderId,
                Order::STATUS_IN_PROGRESS
            );

            // Lấy status ngay sau khi tạo thành công
            $statusData = $this->fetchProviderStatus($providerService, $providerOrderId);

            return [
                'success'           => true,
                'provider_order_id' => $providerOrderId,
                'status_data'       => $statusData,
                'provider_code'     => $provider->code,
            ];
        } catch (\Exception $e) {
            $logger->error($e->getMessage(), $e);
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
        $statusData      = $result['status_data'] ?? null;
        $providerCode    = $result['provider_code'] ?? null;

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

        $logger = OrderActivityLogger::for($order->id)->user($order->user_id);
        if ($providerCode) {
            $logger->provider($providerCode, $providerOrderId);
        }
        $logger->orderUpdated($updateData);
    }

    /**
     * Check status các đơn đang chờ hoàn (processing) từ provider
     * Chạy bằng: php artisan order_place refund-check
     */
    protected function handleRefundCheck()
    {
        $this->info('Bắt đầu kiểm tra trạng thái orders đang chờ hoàn (processing)...');

        $chunkSize = 500;
        $processed = 0;

        $totalCount = Order::where('status', Order::STATUS_PROCESSING)
            ->count();

        if ($totalCount === 0) {
            $this->info('Không có order nào đang chờ hoàn.');
            return 0;
        }

        $this->info("Tìm thấy {$totalCount} orders cần kiểm tra.");

        Order::with(['service.providerService.provider'])
            ->where('status', Order::STATUS_PROCESSING)
            ->whereNotNull('provider_order_id')
            ->orderBy('id')
            ->chunk($chunkSize, function ($orders) use (&$processed, $totalCount) {
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
            $refundAmount = (float) $order->charge_amount;

            DB::table('orders')->where('id', $order->id)->update([
                'status'        => Order::STATUS_FAILED,
                'internal_note' => $errorMessage,
                'error_message' => $errorMessage,
                'retry_count'   => $retryCount,
                'refund_amount' => $refundAmount,
            ]);

            OrderActivityLogger::for($order->id)->user($order->user_id)->orderFailed($errorMessage);

            // Hoàn tiền cho user
            if ($refundAmount > 0) {
                $user = User::lockForUpdate()->find($order->user_id);
                if ($user) {
                    Dongtien::createTransaction(
                        $user,
                        $refundAmount,
                        Dongtien::TYPE_REFUND,
                        "Hoàn tiền đơn hàng #{$order->id} thất bại",
                        ['order_id' => $order->id]
                    );
                    OrderActivityLogger::for($order->id)->user($order->user_id)->refund($refundAmount);
                    $this->info("Order #{$order->id}: Đã hoàn tiền {$refundAmount} cho user #{$user->id}");
                }
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error updating failed order', [
                'order_id' => $order->id,
                'error'    => $e->getMessage(),
            ]);
        }
    }

}

