<?php

namespace App\Services;

use App\Models\AffiliateCommission;
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
    const STATUS_SUCCESS          = 'success';
    const STATUS_PENDING_DUPLICATE = 'pending_duplicate';
    const STATUS_PENDING          = 'pending';

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
     * Parse mã giao dịch SMM từ nội dung chuyển khoản.
     * Pattern: smm + date(8) + random(6) + user_id
     */
    public function extractTransactionCode(string $content): ?string
    {
        if (preg_match('/smm\d{8}.{6}\d+/i', $content, $matches)) {
            return strtoupper($matches[0]);
        }

        return null;
    }

    /**
     * Kiem tra giao dich trung lap theo tid.
     * Tra ve BankAuto neu da ton tai, null neu chua.
     */
    public function findDuplicateTransaction(string $transactionId): ?BankAuto
    {
        return BankAuto::where('tid', $transactionId)->first();
    }

    /**
     * @deprecated Dung findDuplicateTransaction thay the
     */
    public function isDuplicateTransaction(string $transactionId): bool
    {
        return BankAuto::where('tid', $transactionId)->exists();
    }

    /**
     * Xu ly nap tien cho user.
     * - Neu trung tid: luu bank_auto voi status pending_duplicate, KHONG cong tien
     * - Neu khong trung: luu bank_auto status success + tao dongtien (cong tien)
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
        $transactionCode = $this->extractTransactionCode($description);

        // Kiem tra trung lap
        $existing = $this->findDuplicateTransaction($transactionId);
        if ($existing) {
            // Luu lai voi trang thai cho admin kiem tra
            $bankAuto = BankAuto::create([
                'tid'              => $transactionId . '_DUP_' . uniqid(),
                'transaction_code' => $transactionCode,
                'description'      => $description,
                'date'             => $time ?? now()->format('Y-m-d H:i:s'),
                'data'             => json_encode($rawData),
                'amount'           => $amount,
                'type'             => 'bank',
                'deposit_type'     => $source,
                'user_id'          => $userId,
                'status'           => self::STATUS_PENDING_DUPLICATE,
                'note'             => "Trùng giao dịch với bank_auto #{$existing->id} (tid: {$transactionId})",
            ]);

            Log::warning('Duplicate transaction saved for admin review', [
                'tid'          => $transactionId,
                'bank_auto_id' => $bankAuto->id,
                'user_id'      => $userId,
                'amount'       => $amount,
            ]);

            return [
                'success'      => false,
                'duplicate'    => true,
                'bank_auto_id' => $bankAuto->id,
                'message'      => 'Trùng giao dịch, lưu chờ admin duyệt',
            ];
        }

        DB::beginTransaction();
        try {
            $user = User::find($userId);
            if (!$user) {
                DB::rollBack();
                return ['success' => false, 'message' => 'User không tồn tại'];
            }

            $bankAuto = BankAuto::create([
                'tid'              => $transactionId,
                'transaction_code' => $transactionCode,
                'description'      => $description,
                'date'             => $time ?? now()->format('Y-m-d H:i:s'),
                'data'             => json_encode($rawData),
                'amount'           => $amount,
                'type'             => 'bank',
                'deposit_type'     => $source,
                'user_id'          => $userId,
                'status'           => self::STATUS_SUCCESS,
            ]);

            $noidung = match ($source) {
                'sepay'  => 'Nạp tiền thành công (SePay)',
                'pay2s'  => 'Nạp tiền thành công (Pay2s)',
                default  => 'Nạp tiền thành công (Macrodroid)',
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

            $this->creditAffiliateCommission($user, $amount, $bankAuto->id);

            DB::commit();

            Log::info("{$source} deposit success", [
                'user_id'        => $userId,
                'amount'         => $amount,
                'transaction_id' => $transactionId,
                'new_balance'    => $user->fresh()->balance,
            ]);

            return [
                'success'     => true,
                'new_balance' => $user->fresh()->balance,
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("{$source} deposit error", [
                'user_id' => $userId,
                'amount'  => $amount,
                'error'   => $e->getMessage(),
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
        $transactionCode = $this->extractTransactionCode($description);

        return BankAuto::create([
            'tid'              => $transactionId,
            'transaction_code' => $transactionCode,
            'description'      => $description,
            'date'             => $time ?? now()->format('Y-m-d H:i:s'),
            'data'             => json_encode($rawData),
            'amount'           => $amount,
            'type'             => 'bank',
            'deposit_type'     => 'pending',
            'status'           => self::STATUS_PENDING,
            'user_id'          => null,
        ]);
    }

    /**
     * Admin duyet giao dich pending_duplicate hoac pending.
     * Cong tien va tao dongtien.
     */
    public function approveDeposit(BankAuto $bankAuto): array
    {
        if (!in_array($bankAuto->status, [self::STATUS_PENDING_DUPLICATE, self::STATUS_PENDING])) {
            return ['success' => false, 'message' => 'Giao dịch không ở trạng thái chờ duyệt'];
        }

        if (!$bankAuto->user_id) {
            return ['success' => false, 'message' => 'Giao dịch chưa có user, vui lòng gán user trước'];
        }

        DB::beginTransaction();
        try {
            $user = User::find($bankAuto->user_id);
            if (!$user) {
                DB::rollBack();
                return ['success' => false, 'message' => 'User không tồn tại'];
            }

            $dongtien = Dongtien::createTransaction(
                $user,
                $bankAuto->amount,
                Dongtien::TYPE_DEPOSIT,
                'Nạp tiền thành công (Admin duyệt)',
                [
                    'thoigian'       => now(),
                    'payment_method' => 'bank',
                    'payment_ref'    => $bankAuto->tid,
                    'bank_auto_id'   => $bankAuto->id,
                ]
            );

            $bankAuto->update(['status' => self::STATUS_SUCCESS]);

            if ($dongtien) {
                broadcast(new DepositSuccess($dongtien));
            }

            $this->creditAffiliateCommission($user, $bankAuto->amount, $bankAuto->id);

            DB::commit();

            // Cleanup transaction code khỏi Redis/DB sau khi duyệt
            if ($bankAuto->description) {
                $this->cleanupTransactionCode($bankAuto->description);
            }

            return [
                'success'     => true,
                'new_balance' => $user->fresh()->balance,
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Admin approve deposit error', [
                'bank_auto_id' => $bankAuto->id,
                'error'        => $e->getMessage(),
            ]);

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Admin tu choi giao dich.
     */
    public function rejectDeposit(BankAuto $bankAuto, string $reason = ''): array
    {
        if (!in_array($bankAuto->status, [self::STATUS_PENDING_DUPLICATE, self::STATUS_PENDING])) {
            return ['success' => false, 'message' => 'Giao dịch không ở trạng thái chờ duyệt'];
        }

        $bankAuto->update([
            'status' => 'rejected',
            'note'   => $reason ?: $bankAuto->note,
        ]);

        return ['success' => true];
    }

    /**
     * Cộng hoa hồng affiliate cho người giới thiệu khi deposit thành công.
     * Gọi trong cùng transaction DB của processDeposit / approveDeposit.
     */
    private function creditAffiliateCommission(User $user, int $amount, int $bankAutoId): void
    {
        if (!$user->referred_by) {
            return;
        }

        // Chặn self-referral: user không thể tự giới thiệu bản thân
        if ($user->id === (int) $user->referred_by) {
            Log::warning('Self-referral detected, skipping commission', ['user_id' => $user->id]);
            return;
        }

        // Chặn double commission: mỗi deposit chỉ được cộng hoa hồng 1 lần
        if (AffiliateCommission::where('deposit_id', $bankAutoId)->exists()) {
            Log::warning('Affiliate commission already credited for deposit, skipping', [
                'bank_auto_id' => $bankAutoId,
                'user_id'      => $user->id,
            ]);
            return;
        }

        $referrer = User::find($user->referred_by);
        if (!$referrer) {
            return;
        }

        $commissionRate   = (float) ($referrer->affiliate_commission_rate ?? 10.00);
        $commissionAmount = round($amount * $commissionRate / 100, 2);

        if ($commissionAmount <= 0) {
            return;
        }

        try {
            AffiliateCommission::create([
                'user_id'           => $referrer->id,
                'referred_user_id'  => $user->id,
                'order_id'          => null,
                'deposit_id'        => $bankAutoId,
                'source'            => 'deposit',
                'order_amount'      => $amount,
                'commission_rate'   => $commissionRate,
                'commission_amount' => $commissionAmount,
            ]);

            $referrer->increment('affiliate_balance', $commissionAmount);

            Log::info('Affiliate commission credited on deposit', [
                'referrer_id'       => $referrer->id,
                'referred_user_id'  => $user->id,
                'deposit_amount'    => $amount,
                'commission_amount' => $commissionAmount,
                'bank_auto_id'      => $bankAutoId,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to credit affiliate commission', [
                'referrer_id'  => $referrer->id,
                'user_id'      => $user->id,
                'bank_auto_id' => $bankAutoId,
                'error'        => $e->getMessage(),
            ]);
        }
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
