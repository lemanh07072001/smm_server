<?php

namespace App\Console\Commands;

use App\Helpers\OrderActivityLogger;
use App\Helpers\RedisHelper;
use App\Models\OrderActivityLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class SaveActivityLog extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'activity_log:save {--timeout=55 : Số giây tối đa chạy trước khi thoát (mặc định 55s để không overlap với schedule 1 phút)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Lấy activity logs từ Redis và lưu vào MongoDB';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('📝 Bắt đầu lưu activity logs: ' . date('Y-m-d H:i:s'));

        $maxRamMb = 512; // 512MB limit
        $batchSize = 100; // Số lượng logs xử lý mỗi batch
        $totalSaved = 0;
        $totalFailed = 0;
        $timeout = (int) $this->option('timeout');
        $startTime = time();

        while (true) {
            // Thoát nếu vượt quá timeout
            if ((time() - $startTime) >= $timeout) {
                break;
            }

            // Kiểm tra RAM usage
            $currentRamMb = memory_get_usage(true) / 1024 / 1024;
            if ($currentRamMb > $maxRamMb) {
                $this->warn("⚠️ RAM vượt quá {$maxRamMb}MB, dừng xử lý.");
                break;
            }

            try {
                // Lấy log từ Redis queue (FIFO - rpop lấy từ cuối)
                $logJson = Redis::connection(RedisHelper::REDIS_ACTIVITY_LOGS)
                    ->rpop(OrderActivityLogger::KEY_REDIS_ACTIVITY_LOGS);

                if (!$logJson) {
                    // Không còn log trong queue, nghỉ 1 giây rồi tiếp tục
                    echo '.';
                    sleep(1);
                    continue;
                }

                $logData = json_decode($logJson, true);

                if (!$logData) {
                    $this->warn("⚠️ Dữ liệu log không hợp lệ");
                    $totalFailed++;
                    continue;
                }

                // Lưu vào MongoDB
                $saved = $this->saveToMongo($logData);

                if ($saved) {
                    $totalSaved++;
                    $this->line("  ✅ Saved log: order #{$logData['order_id']} - {$logData['type']}");
                } else {
                    $totalFailed++;
                    // Push lại vào queue nếu lưu thất bại
                    Redis::connection(RedisHelper::REDIS_ACTIVITY_LOGS)
                        ->rpush(OrderActivityLogger::KEY_REDIS_ACTIVITY_LOGS, $logJson);
                    $this->warn("  ⚠️ Failed to save, re-queued: order #{$logData['order_id']}");
                }

                // Log progress mỗi batch
                if (($totalSaved + $totalFailed) % $batchSize === 0) {
                    $this->info("📊 Progress: Saved {$totalSaved}, Failed {$totalFailed}");
                }
            } catch (\Exception $e) {
                $this->error("❌ Lỗi: " . $e->getMessage());
                Log::error('SaveActivityLog error', ['error' => $e->getMessage()]);
                sleep(1);
            }
        }

        $this->info("🏁 Hoàn thành: Saved {$totalSaved}, Failed {$totalFailed}");

        return 0;
    }

    /**
     * Lưu log vào MongoDB
     */
    private function saveToMongo(array $logData): bool
    {
        try {
            OrderActivityLog::create([
                'order_id'          => $logData['order_id'],
                'user_id'           => $logData['user_id'] ?? null,
                'provider_code'     => $logData['provider_code'] ?? null,
                'provider_order_id' => $logData['provider_order_id'] ?? null,
                'type'              => $logData['type'],
                'level'             => $logData['level'] ?? OrderActivityLog::LEVEL_INFO,
                'message'           => $logData['message'],
                'request_data'      => $logData['request_data'] ?? null,
                'response_data'     => $logData['response_data'] ?? null,
                'metadata'          => $logData['metadata'] ?? null,
                'duration_ms'       => $logData['duration_ms'] ?? null,
                'created_at'        => $logData['created_at'] ?? now(),
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('SaveActivityLog MongoDB error', [
                'order_id' => $logData['order_id'] ?? null,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
