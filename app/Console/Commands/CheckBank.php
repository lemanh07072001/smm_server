<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\BankAuto;
use App\Models\Dongtien;
use App\Helpers\CurlHelper;
use App\Helpers\RedisHelper;
use App\Helpers\TelegramHelper;
use App\Models\CodeTransaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class CheckBank extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'checkbank';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Kiểm tra giao dịch ngân hàng và xử lý nạp tiền';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Start check bank: ' . date('Y-m-d H:i:s'));

        // 1. Fetch transactions từ API
        $url = 'https://bank.minsoftware.xyz/banking/api.php/SelectBanking?limit=50&taikhoandangnhap=0388251144';
        $curl = new CurlHelper();
        $body = $curl->curl(['url' => $url]);
        $datas = @json_decode($body, true);

        $transactions = $datas['transactions'] ?? [];
        if (empty($transactions)) {
            $this->info('Không có giao dịch mới từ API');
            return;
        }

        // 2. Lấy tất cả Redis data một lần bằng MGET (tối ưu)
        $redisData = $this->getAllRedisTransactions();
        $this->info("Tìm thấy " . count($redisData) . " mã giao dịch trong Redis");

        // 3. Tạo map: transaction_code => redis data để lookup nhanh O(1)
        $codeMap = [];
        foreach ($redisData as $item) {
            if (!empty($item['transaction_code'])) {
                $codeMap[strtoupper($item['transaction_code'])] = $item;
            }
        }

        // 4. Lấy danh sách Reference đã xử lý để check trùng (batch query)
        $allReferences = array_column($transactions, 'Reference');
        $existingRefs = BankAuto::whereIn('tid', $allReferences)->pluck('tid')->toArray();
        $existingRefsMap = array_flip($existingRefs);

        // 5. Loop qua transactions
        $processedCodes = [];

        foreach ($transactions as $transaction) {
            $Reference = $transaction['Reference'] ?? null;
            $description = $transaction['Description'] ?? '';

            if (!$Reference) {
                continue;
            }

            // Skip nếu đã xử lý
            if (isset($existingRefsMap[$Reference])) {
                continue;
            }

            $matchedCode = null;
            $userId = null;

            // Nếu có Redis data, tìm match
            if (!empty($codeMap)) {
                $matchedCode = $this->findMatchingCode($description, $codeMap);

                if ($matchedCode) {
                    $redisItem = $codeMap[$matchedCode];
                    $codeData = $redisItem['transaction_code'];
                    $userId = $this->parseUserId($description, $codeData);
                    $this->info("Match Redis: {$codeData} -> Reference: {$Reference}");
                    $processedCodes[] = $matchedCode;
                }
            }

            // Nếu không match Redis, parse trực tiếp từ description
            if (!$matchedCode) {
            
                $userId = $this->parseUserIdFromDescription($description);
                if ($userId) {
                    $this->info("Parse từ API: User ID {$userId} -> Reference: {$Reference}");
                } else {
                    continue;
                }
            }

            // Xử lý giao dịch
            $this->processTransaction($transaction, $userId);
        }

        // 6. Xóa các mã đã xử lý khỏi Redis và DB
        $this->cleanupProcessedCodes($processedCodes, $codeMap);

        $this->info('End check bank: ' . date('Y-m-d H:i:s'));
    }

    /**
     * Lấy tất cả transactions từ Redis bằng MGET (tối ưu)
     */
    private function getAllRedisTransactions(): array
    {
        $redis = Redis::connection(RedisHelper::REDIS_CODE_TRANSACTIONS);
        $keys = $redis->keys('*');

        if (empty($keys)) {
            return [];
        }

        // Dùng MGET để lấy tất cả values một lần
        $values = $redis->mget($keys);

        $result = [];
        foreach ($keys as $index => $key) {
            $value = $values[$index] ?? null;
            if ($value) {
                $data = json_decode($value, true);
                if ($data && !empty($data['transaction_code'])) {
                    $data['_redis_key'] = $key;
                    $result[] = $data;
                }
            }
        }

        return $result;
    }

    /**
     * Tìm transaction_code match trong description
     */
    private function findMatchingCode(string $description, array $codeMap): ?string
    {
        $descUpper = strtoupper($description);

        foreach (array_keys($codeMap) as $code) {
            if (strpos($descUpper, $code) !== false) {
                return $code;
            }
        }

        return null;
    }

    /**
     * Parse user ID từ description khi có mã Redis
     */
    private function parseUserId(string $description, string $codeData): ?int
    {
        $textToParse = null;

        // Tìm pattern SMM + date + random + user_id
        if (preg_match_all('/smm\d{8}.{6}\d+/', $description, $allMatches)) {
            foreach ($allMatches[0] as $match) {
                if (stripos($match, $codeData) !== false) {
                    $textToParse = $match;
                    break;
                }
            }
        }

        if (empty($textToParse)) {
            $textToParse = $codeData;
        }

        if (preg_match('/smm(\d{8})(.{6})(\d+)/', $textToParse, $matches)) {
            $dateStr = $matches[1];
            $randomStr = $matches[2];
            $userId = (int) $matches[3];

            $this->info("Date: {$dateStr}, Random: {$randomStr}, User ID: {$userId}");
            return $userId;
        }

        return null;
    }

    /**
     * Parse user ID trực tiếp từ description (khi không có Redis)
     * Pattern: SMM + YYYYMMDD (8 số) + random (6 ký tự) + user_id
     */
    private function parseUserIdFromDescription(string $description): ?int
    {
        
        // Tìm pattern SMM + date + random + user_id trong description
        if (preg_match('/smm(\d{8})(.{6})(\d+)/', $description, $matches)) {
            $dateStr = $matches[1];
            $randomStr = $matches[2];
            $userId = (int) $matches[3];

            $this->info("Date: {$dateStr}, Random: {$randomStr}, User ID: {$userId}");
            return $userId;
        }

        return null;
    }

    /**
     * Xử lý một giao dịch (chỉ gọi khi đã match với Redis)
     */
    private function processTransaction(array $transaction, ?int $userId): void
    {
        $Reference = $transaction['Reference'];
        $description = $transaction['Description'] ?? '';
        $amount = (int)($transaction['CD'] . filter_var($transaction['Amount'], FILTER_SANITIZE_NUMBER_INT));

        $bankauto = [
            'tid'         => $Reference,
            'description' => $description,
            'date'        => $transaction['TransactionDate'],
            'data'        => json_encode($transaction),
            'amount'      => $amount,
            'type'        => 'bank'
        ];

        DB::beginTransaction();

        try {
            $bank_auto = BankAuto::create($bankauto);

            // Nạp tiền nếu có userId và amount > 0
            if ($userId && $amount > 0) {
                $bankauto['user_id'] = $userId;
                $user = User::find($userId);

                if ($user) {
                    $str_date = substr($transaction['TransactionDate'], 6, 4) . '-'
                        . substr($transaction['TransactionDate'], 3, 2) . '-'
                        . substr($transaction['TransactionDate'], 0, 2) . ' '
                        . substr($transaction['PCTime'], 0, 2) . ':'
                        . substr($transaction['PCTime'], 2, 2) . ':'
                        . substr($transaction['PCTime'], 4, 2);

                    Dongtien::createTransaction($user, $amount, Dongtien::TYPE_DEPOSIT, 'Nạp tiền thành công.', [
                        'thoigian'       => date('Y-m-d H:i:s', strtotime($str_date)),
                        'payment_method' => 'bank',
                        'payment_ref'    => $Reference,
                        'datas'          => json_encode($bankauto),
                        'bank_auto_id'   => $bank_auto->id,
                    ]);

                    $this->info("Nạp tiền thành công cho user {$userId}: " . number_format($amount) . " VND");
                }
            }

            DB::commit();
            $this->info("Thêm giao dịch: {$Reference}");

        } catch (\Exception $e) {
            DB::rollBack();
            $this->error("Lỗi xử lý giao dịch {$Reference}: " . $e->getMessage());
            logger()->error('CheckBank transaction error', [
                'reference' => $Reference,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Xóa các mã đã xử lý khỏi Redis và Database
     */
    private function cleanupProcessedCodes(array $processedCodes, array $codeMap): void
    {
        if (empty($processedCodes)) {
            return;
        }

        $redis = Redis::connection(RedisHelper::REDIS_CODE_TRANSACTIONS);

        // Lấy tất cả transaction_code cần xóa
        $codesToDelete = [];
        $keysToDelete = [];

        foreach ($processedCodes as $code) {
            if (isset($codeMap[$code])) {
                $item = $codeMap[$code];
                $codesToDelete[] = $item['transaction_code'];
                $keysToDelete[] = $item['_redis_key'];
            }
        }

        // Xóa trong Database bằng batch delete
        if (!empty($codesToDelete)) {
            $deleted = CodeTransaction::whereIn('transaction_code', $codesToDelete)->delete();
            $this->info("Đã xóa {$deleted} mã giao dịch trong Database");
        }

        // Xóa trong Redis bằng DEL nhiều keys
        if (!empty($keysToDelete)) {
            $redis->del(...$keysToDelete);
            $this->info("Đã xóa " . count($keysToDelete) . " keys trong Redis");
        }
    }
}
