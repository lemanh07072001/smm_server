<?php

namespace App\Services;

use App\Models\BankAuto;
use App\Models\User;
use App\Models\Dongtien;
use App\Models\CodeTransaction;
use App\Helpers\RedisHelper;
use App\Events\DepositSuccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;

class DepositService
{
    /**
     * Tim user ID tu ma giao dich trong noi dung text.
     * Kiem tra Redis truoc, fallback sang regex parsing.
     */
    public function findUserFromContent(string $content): ?int
    {
        $contentLower = strtolower($content);

        // 1. Thu tim tu Redis truoc
        $userId = $this->findUserFromRedis($contentLower);
        if ($userId) {
            return $userId;
        }

        // 2. Parse truc tiep tu content
        // Pattern: SMM + date(8) + random(6) + user_id
        if (preg_match('/smm(\d{8})(.{6})(\d+)/', $contentLower, $matches)) {
            $userId = (int) $matches[3];
            if ($userId > 0 && User::find($userId)) {
                return $userId;
            }
        }

        return null;
    }

    /**
     * Tim user tu Redis transaction code cache.
     */
    private function findUserFromRedis(string $contentLower): ?int
    {
        try {
            $redis = Redis::connection(RedisHelper::REDIS_CODE_TRANSACTIONS);
            $keys = $redis->keys('*');

            if (empty($keys)) {
                return null;
            }

            $values = $redis->mget($keys);

            foreach ($values as $value) {
                if (!$value) continue;

                $data = json_decode($value, true);
                if (!$data || empty($data['transaction_code'])) continue;

                $code = strtolower($data['transaction_code']);
                if (strpos($contentLower, $code) !== false) {
                    if (preg_match('/smm(\d{8})(.{6})(\d+)/', $code, $matches)) {
                        return (int) $matches[3];
                    }
                }
            }
        } catch (\Exception $e) {
            Log::error('Error finding user from Redis', ['error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Xu ly nap tien cho user.
     *
     * @param int $userId
     * @param int $amount
     * @param string $transactionId - Ma giao dich duy nhat
     * @param string $description - Mo ta giao dich
     * @param string|null $time - Thoi gian giao dich
     * @param array $rawData - Du lieu goc de luu audit trail
     * @param string $source - Nguon: 'macrodroid', 'sepay'
     * @return array{success: bool, new_balance?: float, message?: string}
     */
    public function processDeposit(
        int $userId,
        int $amount,
        string $transactionId,
        string $description,
        ?string $time,
        array $rawData,
        string $source = 'macrodroid'
    ): array {
        DB::beginTransaction();

        try {
            $user = User::find($userId);
            if (!$user) {
                DB::rollBack();
                return ['success' => false, 'message' => 'User không tồn tại'];
            }

            $bankAuto = BankAuto::create([
                'tid'          => $transactionId,
                'description'  => $description,
                'date'         => $time ?? now()->format('d/m/Y H:i:s'),
                'data'         => json_encode($rawData),
                'amount'       => $amount,
                'type'         => 'bank',
                'deposit_type' => 'auto',
                'user_id'      => $userId,
            ]);

            $noidung = match ($source) {
                'sepay'   => 'Nạp tiền thành công (SePay)',
                default   => 'Nạp tiền thành công (Macrodroid)',
            };

            $dongtien = Dongtien::createTransaction(
                $user,
                $amount,
                Dongtien::TYPE_DEPOSIT,
                $noidung,
                [
                    'thoigian'       => $time ? date('Y-m-d H:i:s', strtotime($time)) : now(),
                    'payment_method' => 'bank',
                    'payment_ref'    => $transactionId,
                    'datas'          => json_encode($rawData),
                    'bank_auto_id'   => $bankAuto->id,
                ]
            );

            $this->cleanupTransactionCode($description);

            if ($dongtien) {
                broadcast(new DepositSuccess($dongtien));
            }

            DB::commit();

            Log::info("{$source} deposit success", [
                'user_id' => $userId,
                'amount' => $amount,
                'transaction_id' => $transactionId,
                'new_balance' => $user->fresh()->balance,
            ]);

            return [
                'success' => true,
                'new_balance' => $user->fresh()->balance,
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("{$source} deposit error", [
                'user_id' => $userId,
                'amount' => $amount,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Luu giao dich pending (chua match duoc user).
     */
    public function savePendingDeposit(
        string $transactionId,
        string $description,
        ?string $time,
        int $amount,
        array $rawData
    ): BankAuto {
        return BankAuto::create([
            'tid'          => $transactionId,
            'description'  => $description,
            'date'         => $time ?? now()->format('d/m/Y H:i:s'),
            'data'         => json_encode($rawData),
            'amount'       => $amount,
            'type'         => 'bank',
            'deposit_type' => 'pending',
            'user_id'      => null,
        ]);
    }

    /**
     * Kiem tra giao dich trung lap.
     */
    public function isDuplicateTransaction(string $transactionId): bool
    {
        return BankAuto::where('tid', $transactionId)->exists();
    }

    /**
     * Xoa ma giao dich da xu ly khoi Redis va Database.
     */
    public function cleanupTransactionCode(string $content): void
    {
        $contentLower = strtolower($content);

        try {
            $redis = Redis::connection(RedisHelper::REDIS_CODE_TRANSACTIONS);
            $keys = $redis->keys('*');

            if (empty($keys)) {
                return;
            }

            $values = $redis->mget($keys);

            foreach ($keys as $index => $key) {
                $value = $values[$index] ?? null;
                if (!$value) continue;

                $data = json_decode($value, true);
                if (!$data || empty($data['transaction_code'])) continue;

                $code = strtolower($data['transaction_code']);
                if (strpos($contentLower, $code) !== false) {
                    $redis->del($key);
                    CodeTransaction::where('transaction_code', $data['transaction_code'])->delete();
                    Log::info('Cleaned up transaction code', ['code' => $data['transaction_code']]);
                    break;
                }
            }
        } catch (\Exception $e) {
            Log::error('Error cleaning up transaction code', ['error' => $e->getMessage()]);
        }
    }
}
