<?php

namespace App\Services;

use App\Models\AffiliateCommission;
use App\Models\BankAuto;
use App\Models\DepositLog;
use App\Models\TransactionBank;
use App\Models\User;
use App\Models\Dongtien;
use App\Events\DepositSuccess;
use App\Helpers\TelegramHelper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DepositService
{
    const STATUS_SUCCESS          = 'success';
    const STATUS_PENDING_DUPLICATE = 'pending_duplicate';
    const STATUS_PENDING          = 'pending';

    /**
     * Tìm user ID từ nội dung chuyển khoản.
     * Tìm mã NAP + 6 chữ số trong content, tra cứu cột deposit_code trên bảng users.
     */
    public function findUserFromContent(string $content): ?int
    {
        $contentUpper = strtoupper($content);

        if (preg_match('/NAP\d{6}/', $contentUpper, $matches)) {
            $user = User::where('deposit_code', $matches[0])->first();
            if ($user) {
                return $user->id;
            }
        }

        return null;
    }

    /**
     * Trích xuất deposit_code từ nội dung chuyển khoản.
     */
    public function extractTransactionCode(string $content): ?string
    {
        $contentUpper = strtoupper($content);

        if (preg_match('/NAP\d{6}/', $contentUpper, $matches)) {
            return $matches[0];
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
     *
     * Logic:
     * 1. Trùng tid → lưu pending_duplicate, KHÔNG cộng tiền
     * 2. Tìm bản ghi pending còn hạn của user:
     *    - Nếu có → update bản ghi đó thành success (ghi note nếu lệch amount)
     *    - Nếu không → tạo bản ghi mới success
     * 3. Cộng tiền theo amount thực tế Pay2s gửi
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
        $depositType     = $source === 'manual' ? 'manual' : 'auto';
        $paymentChannel  = match ($source) {
            'momo'                    => 'momo',
            'zalopay'                 => 'zalopay',
            'vnpay'                   => 'vnpay',
            'binance'                 => 'binance',
            'pay2s', 'sepay',
            'macrodroid', 'manual'    => 'bank',
            default                   => 'bank',
        };
        $logData = []; // Tích lũy log, ghi sau commit để tránh rollback MySQL

        DB::beginTransaction();
        try {
            // 1. Kiểm tra trùng tid → bỏ qua hoàn toàn
            $existing = BankAuto::where('tid', $transactionId)->lockForUpdate()->first();
            if ($existing) {
                DB::rollBack();

                $this->mlog('duplicate_tid', 'warning', [
                    'source'       => $source,
                    'tid'          => $transactionId,
                    'user_id'      => $userId,
                    'amount'       => $amount,
                    'bank_auto_id' => $existing->id,
                    'raw_payload'  => $rawData,
                    'message'      => "Trùng tid với bank_auto #{$existing->id}, bỏ qua",
                ]);

                Log::info('Duplicate transaction, skipped', [
                    'tid'          => $transactionId,
                    'bank_auto_id' => $existing->id,
                    'user_id'      => $userId,
                ]);

                return [
                    'success'   => false,
                    'duplicate' => true,
                    'message'   => 'Trùng giao dịch, bỏ qua',
                ];
            }

            $user = User::find($userId);
            if (!$user) {
                DB::rollBack();
                return ['success' => false, 'message' => 'User không tồn tại'];
            }

            // 2. Chỉ tìm bản ghi PENDING — webhook đến sau khi expired không tự cộng, nhờ Admin xử lý
            $pendingRecord = BankAuto::where('user_id', $userId)
                ->where('status', self::STATUS_PENDING)
                ->whereNull('tid')
                ->latest()
                ->lockForUpdate()
                ->first();

            if ($pendingRecord) {
                // Guard: sau khi lock, kiểm tra lại status — có thể đã được xử lý bởi request khác
                if ($pendingRecord->status === self::STATUS_SUCCESS) {
                    DB::rollBack();
                    $this->mlog('duplicate_tid', 'warning', [
                        'source'       => $source,
                        'tid'          => $transactionId,
                        'user_id'      => $userId,
                        'amount'       => $amount,
                        'bank_auto_id' => $pendingRecord->id,
                        'raw_payload'  => $rawData,
                        'message'      => "BankAuto #{$pendingRecord->id} đã là success, bỏ qua",
                    ]);
                    return ['success' => false, 'duplicate' => true, 'message' => 'Giao dịch đã được xử lý'];
                }

                // Kiểm tra lệch amount → tạo bản ghi mới pending_duplicate, GIỮ NGUYÊN pending gốc
                // Không được quay bản ghi pending gốc về trạng thái khác, chỉ hết hạn mới expire
                if ($pendingRecord->amount !== $amount) {
                    $dupRecord = BankAuto::create([
                        'tid'              => $transactionId,
                        'transaction_code' => $transactionCode,
                        'description'      => $description,
                        'date'             => $time ?? now()->format('Y-m-d H:i:s'),
                        'data'             => json_encode($rawData),
                        'amount'           => $amount,
                        'type'             => 'bank',
                        'deposit_type'     => $depositType,
                        'payment_channel'  => $paymentChannel,
                        'user_id'          => $userId,
                        'status'           => self::STATUS_PENDING_DUPLICATE,
                        'note'             => "Lệch số tiền: user tạo QR {$pendingRecord->amount}đ, thực tế chuyển {$amount}đ. Chờ admin duyệt.",
                    ]);

                    DB::commit();

                    $this->mlog('amount_mismatch', 'warning', [
                        'source'          => $source,
                        'tid'             => $transactionId,
                        'user_id'         => $userId,
                        'bank_auto_id'    => $dupRecord->id,
                        'pending_id'      => $pendingRecord->id,
                        'expected_amount' => $pendingRecord->amount,
                        'actual_amount'   => $amount,
                        'deposit_code'    => $transactionCode,
                        'raw_payload'     => $rawData,
                        'message'         => "Lệch số tiền: tạo QR {$pendingRecord->amount}đ, chuyển thực tế {$amount}đ. Bản ghi pending gốc #{$pendingRecord->id} giữ nguyên.",
                    ]);

                    Log::warning('Deposit amount mismatch, saved for admin review', [
                        'user_id'         => $userId,
                        'expected_amount' => $pendingRecord->amount,
                        'actual_amount'   => $amount,
                        'tid'             => $transactionId,
                        'pending_id'      => $pendingRecord->id,
                    ]);

                    return [
                        'success' => false,
                        'message' => 'Lệch số tiền, lưu chờ admin duyệt',
                    ];
                }

                // Amount khớp → update pending thành success
                $pendingRecord->update([
                    'tid'              => $transactionId,
                    'transaction_code' => $transactionCode,
                    'description'      => $description,
                    'date'             => $time ?? now()->format('Y-m-d H:i:s'),
                    'data'             => json_encode($rawData),
                    'deposit_type'     => $depositType,
                    'payment_channel'  => $paymentChannel,
                    'status'           => self::STATUS_SUCCESS,
                    'expires_at'       => null,
                ]);

                $bankAuto = $pendingRecord->fresh();

                $logData = ['bank_auto_id' => $bankAuto->id, 'message' => 'Amount khớp, cập nhật pending → success'];

            } else {
                // Không có pending → lưu chờ admin duyệt, KHÔNG cộng tiền
                $bankAuto = BankAuto::create([
                    'tid'              => $transactionId,
                    'transaction_code' => $transactionCode,
                    'description'      => $description,
                    'date'             => $time ?? now()->format('Y-m-d H:i:s'),
                    'data'             => json_encode($rawData),
                    'amount'           => $amount,
                    'type'             => 'bank',
                    'deposit_type'     => $depositType,
                    'payment_channel'  => $paymentChannel,
                    'user_id'          => $userId,
                    'status'           => self::STATUS_PENDING_DUPLICATE,
                    'note'             => 'Không tìm thấy giao dịch QR pending, chờ admin duyệt',
                ]);

                DB::commit();

                $this->mlog('no_pending', 'warning', [
                    'source'       => $source,
                    'tid'          => $transactionId,
                    'user_id'      => $userId,
                    'amount'       => $amount,
                    'bank_auto_id' => $bankAuto->id,
                    'deposit_code' => $transactionCode,
                    'raw_payload'  => $rawData,
                    'message'      => 'Không có QR pending, lưu chờ admin duyệt',
                ]);

                Log::warning('No pending record found, saved for admin review', [
                    'user_id' => $userId,
                    'amount'  => $amount,
                    'tid'     => $transactionId,
                ]);

                return [
                    'success' => false,
                    'message' => 'Không có giao dịch QR pending, lưu chờ admin duyệt',
                ];
            }

            // 3. Cộng tiền theo amount thực tế
            $noidung = match ($source) {
                'sepay'  => 'Nạp tiền thành công (SePay)',
                'pay2s'  => 'Nạp tiền thành công (Pay2s)',
                default  => 'Nạp tiền thành công (Macrodroid)',
            };

            // Đánh dấu is_processed=true và processed_at trước khi cộng tiền (dedup tầng 1)
            $bankAuto->update(['is_processed' => true, 'processed_at' => now()]);

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

            if ($dongtien) {
                broadcast(new DepositSuccess($dongtien));
            }

            $user->increment('total_deposited', $amount);

            $this->creditAffiliateCommission($user, $amount, $bankAuto->id);

            DB::commit();

            $newBalance = $user->fresh()->balance;

            // Mark TransactionBank is_processed=true sau commit (ngoài transaction)
            TransactionBank::where('tid', $transactionId)
                ->where('is_processed', false)
                ->update(['is_processed' => true, 'bank_auto_id' => $bankAuto->id]);

            // Telegram notification sau commit
            try {
                $amountFormatted = number_format($amount, 0, ',', '.');
                $balanceFormatted = number_format($newBalance, 0, ',', '.');
                TelegramHelper::sendNotifyNapTienSystem(
                    "💰 Nạp tiền ({$source}) — {$user->name} (#{$userId}) — {$amountFormatted}đ — Số dư: {$balanceFormatted}đ"
                );
            } catch (\Exception $e) {
                Log::warning('Telegram deposit notification failed', ['error' => $e->getMessage()]);
            }

            // Ghi log sau commit, tránh MongoDB exception rollback MySQL
            $this->mlog('pending_matched', 'success', [
                'source'       => $source,
                'tid'          => $transactionId,
                'user_id'      => $userId,
                'amount'       => $amount,
                'bank_auto_id' => $bankAuto->id,
                'deposit_code' => $transactionCode,
                'raw_payload'  => $rawData,
                'message'      => $logData['message'] ?? 'Amount khớp, cập nhật pending → success',
            ]);

            $this->mlog('deposit_success', 'success', [
                'source'       => $source,
                'tid'          => $transactionId,
                'user_id'      => $userId,
                'amount'       => $amount,
                'bank_auto_id' => $bankAuto->id,
                'deposit_code' => $transactionCode,
                'raw_payload'  => $rawData,
                'new_balance'  => $newBalance,
                'message'      => 'Cộng tiền thành công',
            ]);

            Log::info("{$source} deposit success", [
                'user_id'        => $userId,
                'amount'         => $amount,
                'transaction_id' => $transactionId,
                'new_balance'    => $newBalance,
            ]);

            return [
                'success'     => true,
                'new_balance' => $newBalance,
            ];
        } catch (\Exception $e) {
            DB::rollBack();

            $this->mlog('deposit_exception', 'error', [
                'source'      => $source,
                'tid'         => $transactionId,
                'user_id'     => $userId,
                'amount'      => $amount,
                'raw_payload' => $rawData,
                'message'     => $e->getMessage(),
                'context'     => [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ],
            ]);

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
        array $rawData,
        string $source = 'unknown'
    ): BankAuto {
        $transactionCode = $this->extractTransactionCode($description);
        $depositType     = $source === 'manual' ? 'manual' : 'auto';
        $paymentChannel  = match ($source) {
            'momo'                    => 'momo',
            'zalopay'                 => 'zalopay',
            'vnpay'                   => 'vnpay',
            'binance'                 => 'binance',
            'pay2s', 'sepay',
            'macrodroid', 'manual'    => 'bank',
            default                   => 'bank',
        };

        $bankAuto = BankAuto::create([
            'tid'              => $transactionId,
            'transaction_code' => $transactionCode,
            'description'      => $description,
            'date'             => $time ?? now()->format('Y-m-d H:i:s'),
            'data'             => json_encode($rawData),
            'amount'           => $amount,
            'type'             => 'bank',
            'deposit_type'     => $depositType,
            'payment_channel'  => $paymentChannel,
            'status'           => self::STATUS_PENDING,
            'note'             => 'Không tìm thấy user từ nội dung chuyển khoản, chờ admin duyệt',
            'user_id'          => null,
        ]);

        $this->mlog('no_user_found', 'warning', [
            'source'       => $source,
            'tid'          => $transactionId,
            'amount'       => $amount,
            'deposit_code' => $transactionCode,
            'bank_auto_id' => $bankAuto->id,
            'raw_payload'  => $rawData,
            'message'      => 'Không tìm thấy user từ nội dung, lưu chờ admin duyệt',
            'context'      => ['description' => $description],
        ]);

        return $bankAuto;
    }

    /**
     * Luồng nạp tay từ TransactionBank (Admin chọn GD ngân hàng raw → cộng tiền).
     *
     * 1. Validate đã làm ở controller (is_processed, transfer_type=IN, amount>0)
     * 2. Tìm BankAuto PENDING của user + amount khớp
     *    Không tìm thấy → tạo BankAuto mới:
     *      transaction_code = "MANUAL_{txn_id}_{timestamp}"
     *      deposit_type     = 'manual', status = PENDING
     * 3. Guard: status=success hoặc đã có dongtien → rollback
     * 4. tid = "MANUAL_BANK_{txn_id}_{timestamp}" → cộng tiền
     * 5. transaction_bank.is_processed = true, bank_auto_id = bankAuto->id
     */
    public function manualCreditFromTransaction(
        TransactionBank $txn,
        int $userId,
        string $adminNote,
        int $adminId
    ): array {
        $user = User::find($userId);
        if (!$user) {
            return ['success' => false, 'message' => 'User không tồn tại'];
        }

        $amount = $txn->amount;

        DB::beginTransaction();
        try {
            // Bước 2: Tìm BankAuto pending/expired/cancelled của user + amount khớp
            $bankAuto = BankAuto::where('user_id', $userId)
                ->whereIn('status', [self::STATUS_PENDING, 'expired', 'cancelled'])
                ->where('amount', $amount)
                ->whereNull('tid')
                ->latest()
                ->lockForUpdate()
                ->first();

            if (!$bankAuto) {
                // Tạo BankAuto mới
                $bankAuto = BankAuto::create([
                    'user_id'          => $userId,
                    'amount'           => $amount,
                    'type'             => 'bank',
                    'deposit_type'     => 'manual',
                    'payment_channel'  => 'bank',
                    'transaction_code' => 'MANUAL_' . $txn->id . '_' . now()->timestamp,
                    'description'      => $txn->content ?? '',
                    'date'             => now()->format('Y-m-d H:i:s'),
                    'status'           => self::STATUS_PENDING,
                    'note'             => $adminNote,
                    'is_processed'     => false,
                ]);
            }

            // Bước 3: Guard sau lock
            if ($bankAuto->status === self::STATUS_SUCCESS) {
                DB::rollBack();
                return ['success' => false, 'message' => 'Giao dịch đã được xử lý (status=success)'];
            }

            if (Dongtien::where('bank_auto_id', $bankAuto->id)->exists()) {
                DB::rollBack();
                return ['success' => false, 'message' => 'Giao dịch đã được cộng tiền trước đó'];
            }

            // Bước 4: tid + cộng tiền
            $tid = 'MANUAL_BANK_' . $txn->id . '_' . now()->timestamp;

            $bankAuto->update([
                'status'       => self::STATUS_SUCCESS,
                'tid'          => $tid,
                'deposit_type' => 'manual',
                'note'         => $adminNote,
                'is_processed' => true,
                'processed_at' => now(),
                'expires_at'   => null,
            ]);

            $dongtien = Dongtien::createTransaction(
                $user,
                $amount,
                Dongtien::TYPE_DEPOSIT,
                'Nạp tiền thành công (Admin cộng thủ công)',
                [
                    'thoigian'       => now(),
                    'payment_method' => 'bank',
                    'payment_ref'    => $tid,
                    'bank_auto_id'   => $bankAuto->id,
                ]
            );

            if ($dongtien) {
                broadcast(new DepositSuccess($dongtien));
            }

            $user->increment('total_deposited', $amount);

            // Bước 5: đánh dấu TransactionBank đã xử lý
            $txn->update([
                'is_processed' => true,
                'bank_auto_id' => $bankAuto->id,
            ]);

            $this->creditAffiliateCommission($user, $amount, $bankAuto->id);

            DB::commit();

            $this->mlog('admin_manual_credit_txn', 'success', [
                'source'              => 'admin',
                'tid'                 => $tid,
                'user_id'             => $userId,
                'amount'              => $amount,
                'bank_auto_id'        => $bankAuto->id,
                'transaction_bank_id' => $txn->id,
                'message'             => "Admin #{$adminId} cộng tiền thủ công từ TransactionBank #{$txn->id}: {$adminNote}",
            ]);

            return [
                'success'      => true,
                'bank_auto_id' => $bankAuto->id,
                'tid'          => $tid,
                'new_balance'  => $user->fresh()->balance,
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('manualCreditFromTransaction error', [
                'transaction_bank_id' => $txn->id,
                'user_id'             => $userId,
                'error'               => $e->getMessage(),
            ]);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Admin cộng tiền thủ công cho BankAuto đã được chọn.
     *
     * Bước 2: Tìm BankAuto PENDING của cùng user có amount khớp + transaction_code IS NOT NULL.
     *         Nếu không có → tạo BankAuto mới (transaction_code = MANUAL_{userId}_{timestamp}).
     * Bước 3: LOCK FOR UPDATE:
     *         - status = success → ❌ rollback "Đã xử lý (status=success)"
     *         - tid trùng record khác → ❌ rollback "Giao dịch tid=... đã xử lý"
     *         Status được cộng: pending, expired, cancelled, mới tạo.
     * Bước 4: BankAuto → success, tid = MANUAL_BANK_{id}_{ts}, is_processed = true.
     *         Dongtien.create → user.sodu += amount → broadcast → affiliate.
     */
    public function manualCredit(BankAuto $inputBankAuto, string $adminNote, int $adminId): array
    {
        $userId = $inputBankAuto->user_id;
        $amount = $inputBankAuto->amount;

        $user = User::find($userId);
        if (!$user) {
            return ['success' => false, 'message' => 'User không tồn tại'];
        }

        DB::beginTransaction();
        try {
            // Bước 2: Tìm BankAuto pending/expired/cancelled của user, khớp amount, có transaction_code
            $bankAuto = BankAuto::where('user_id', $userId)
                ->whereIn('status', [self::STATUS_PENDING, 'expired', 'cancelled'])
                ->where('amount', $amount)
                ->whereNotNull('transaction_code')
                ->where('id', $inputBankAuto->id) // ưu tiên đúng record được chọn
                ->lockForUpdate()
                ->first();

            if (!$bankAuto) {
                // Thử tìm bất kỳ record khớp (trường hợp record chọn không có transaction_code)
                $bankAuto = BankAuto::where('user_id', $userId)
                    ->whereIn('status', [self::STATUS_PENDING, 'expired', 'cancelled'])
                    ->where('amount', $amount)
                    ->whereNotNull('transaction_code')
                    ->latest()
                    ->lockForUpdate()
                    ->first();
            }

            if (!$bankAuto) {
                // Tạo BankAuto mới
                $txnCode = 'MANUAL_' . $userId . '_' . now()->timestamp;
                $bankAuto = BankAuto::create([
                    'user_id'          => $userId,
                    'amount'           => $amount,
                    'type'             => 'bank',
                    'deposit_type'     => 'manual',
                    'payment_channel'  => 'bank',
                    'transaction_code' => $txnCode,
                    'description'      => $adminNote,
                    'date'             => now()->format('Y-m-d H:i:s'),
                    'status'           => self::STATUS_PENDING,
                    'note'             => $adminNote,
                    'is_processed'     => false,
                ]);
            }

            // Bước 3: Lock — kiểm tra lớp 2
            if ($bankAuto->status === self::STATUS_SUCCESS) {
                DB::rollBack();
                return ['success' => false, 'message' => 'Đã xử lý (status=success)'];
            }

            if (Dongtien::where('bank_auto_id', $bankAuto->id)->exists()) {
                DB::rollBack();
                return ['success' => false, 'message' => 'Giao dịch đã được cộng tiền trước đó'];
            }

            $tid = 'MANUAL_BANK_' . $bankAuto->id . '_' . now()->timestamp;

            if (BankAuto::where('tid', $tid)->where('id', '!=', $bankAuto->id)->exists()) {
                DB::rollBack();
                return ['success' => false, 'message' => "Giao dịch tid={$tid} đã xử lý"];
            }

            // Bước 4: Cộng tiền
            $bankAuto->update([
                'status'          => self::STATUS_SUCCESS,
                'tid'             => $tid,
                'deposit_type'    => 'manual',
                'payment_channel' => 'bank',
                'note'            => $adminNote,
                'is_processed'    => true,
                'expires_at'      => null,
            ]);

            $dongtien = Dongtien::createTransaction(
                $user,
                $amount,
                Dongtien::TYPE_DEPOSIT,
                'Nạp tiền thành công (Admin cộng thủ công)',
                [
                    'thoigian'       => now(),
                    'payment_method' => 'bank',
                    'payment_ref'    => $tid,
                    'bank_auto_id'   => $bankAuto->id,
                ]
            );

            if ($dongtien) {
                broadcast(new DepositSuccess($dongtien));
            }

            $this->creditAffiliateCommission($user, $amount, $bankAuto->id);

            DB::commit();

            $this->mlog('admin_manual_credit', 'success', [
                'source'       => 'admin',
                'tid'          => $tid,
                'user_id'      => $userId,
                'amount'       => $amount,
                'bank_auto_id' => $bankAuto->id,
                'message'      => "Admin #{$adminId} cộng tiền thủ công {$amount}đ: {$adminNote}",
            ]);

            return [
                'success'      => true,
                'bank_auto_id' => $bankAuto->id,
                'tid'          => $tid,
                'new_balance'  => $user->fresh()->balance,
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Admin manual credit error', [
                'bank_auto_id' => $inputBankAuto->id,
                'error'        => $e->getMessage(),
            ]);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Admin cộng tiền thủ công cho giao dịch pending/expired.
     * Khác approveDeposit: cho phép truyền tid và admin_note, hoạt động với cả expired.
     */
    public function creditDeposit(BankAuto $bankAuto, string $tid, string $adminNote, int $adminId): array
    {
        if ($bankAuto->status === self::STATUS_SUCCESS) {
            return ['success' => false, 'message' => 'Giao dịch đã thành công, không thể cộng lại'];
        }

        if (!$bankAuto->user_id) {
            return ['success' => false, 'message' => 'Giao dịch chưa có user, vui lòng gán user trước'];
        }

        // Kiểm tra tid trùng
        if ($tid && BankAuto::where('tid', $tid)->where('id', '!=', $bankAuto->id)->exists()) {
            return ['success' => false, 'message' => 'Mã TID đã tồn tại trong hệ thống'];
        }

        DB::beginTransaction();
        try {
            if (Dongtien::where('bank_auto_id', $bankAuto->id)->exists()) {
                DB::rollBack();
                return ['success' => false, 'message' => 'Giao dịch đã được cộng tiền trước đó'];
            }

            $user = User::find($bankAuto->user_id);
            if (!$user) {
                DB::rollBack();
                return ['success' => false, 'message' => 'User không tồn tại'];
            }

            $bankAuto->update([
                'status'    => self::STATUS_SUCCESS,
                'tid'       => $tid ?: $bankAuto->tid,
                'note'      => $adminNote,
                'expires_at'=> null,
            ]);

            $dongtien = Dongtien::createTransaction(
                $user,
                $bankAuto->amount,
                Dongtien::TYPE_DEPOSIT,
                'Nạp tiền thành công (Admin cộng thủ công)',
                [
                    'thoigian'       => now(),
                    'payment_method' => 'bank',
                    'payment_ref'    => $bankAuto->tid,
                    'bank_auto_id'   => $bankAuto->id,
                ]
            );

            if ($dongtien) {
                broadcast(new DepositSuccess($dongtien));
            }

            $this->creditAffiliateCommission($user, $bankAuto->amount, $bankAuto->id);

            DB::commit();

            $this->mlog('admin_credited', 'success', [
                'source'       => 'admin',
                'tid'          => $bankAuto->tid,
                'user_id'      => $bankAuto->user_id,
                'amount'       => $bankAuto->amount,
                'bank_auto_id' => $bankAuto->id,
                'message'      => "Admin #{$adminId} cộng tiền thủ công: {$adminNote}",
            ]);

            return [
                'success'     => true,
                'new_balance' => $user->fresh()->balance,
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Admin credit deposit error', [
                'bank_auto_id' => $bankAuto->id,
                'error'        => $e->getMessage(),
            ]);
            return ['success' => false, 'message' => $e->getMessage()];
        }
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
            // Chặn double approve: kiểm tra đã có dongtien cho bank_auto này chưa
            if (Dongtien::where('bank_auto_id', $bankAuto->id)->exists()) {
                DB::rollBack();
                return ['success' => false, 'message' => 'Giao dịch đã được duyệt trước đó'];
            }

            $user = User::find($bankAuto->user_id);
            if (!$user) {
                DB::rollBack();
                return ['success' => false, 'message' => 'User không tồn tại'];
            }

            $bankAuto->update(['status' => self::STATUS_SUCCESS]);

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

            if ($dongtien) {
                broadcast(new DepositSuccess($dongtien));
            }

            $this->creditAffiliateCommission($user, $bankAuto->amount, $bankAuto->id);

            DB::commit();

            $this->mlog('admin_approved', 'success', [
                'source'       => 'admin',
                'tid'          => $bankAuto->tid,
                'user_id'      => $bankAuto->user_id,
                'amount'       => $bankAuto->amount,
                'bank_auto_id' => $bankAuto->id,
                'message'      => 'Admin duyệt giao dịch thành công',
            ]);

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
     * Ghi log từng bước xử lý vào MongoDB.
     */
    private function mlog(string $step, string $status, array $data = []): void
    {
        try {
            DepositLog::create(array_merge(['step' => $step, 'status' => $status], $data));
        } catch (\Exception $e) {
            Log::error('DepositLog MongoDB write failed', ['error' => $e->getMessage()]);
        }
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

}
