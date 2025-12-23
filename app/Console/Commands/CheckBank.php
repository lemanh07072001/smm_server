<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use App\Models\User;
use App\Models\BankAuto;
use App\Models\Dongtien;
use App\Helpers\CurlHelper;
use App\Helpers\RedisHelper;
use App\Helpers\TelegramHelper;
use App\Models\CodeTransaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

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
        // $url = 'http://namdubaiz.xyz/api1.php?user=0975771679&pass=GetAPI@1234';
        $url = 'https://bank.minsoftware.xyz/banking/api.php/SelectBanking?limit=50&taikhoandangnhap=0388251144';
        $curl = new CurlHelper();
        $body = $curl->curl(['url' => $url]);
        $datas = @json_decode($body, true);

        $keys = RedisHelper::getAllKeys(RedisHelper::REDIS_CODE_TRANSACTIONS);

        foreach ($keys as $key) {
            $data = RedisHelper::get($key, RedisHelper::REDIS_CODE_TRANSACTIONS);
            $data = json_decode($data, true);

            // Bỏ qua nếu data null hoặc không có transaction_code
            if (empty($data) || !isset($data['transaction_code'])) {
                $this->warn("⚠️  Bỏ qua key không hợp lệ: {$key}");
                continue;
            }

            $codeData = $data['transaction_code'];

            if (isset($datas['transactions']) && $datas['transactions']) {
                $filtered = array_filter($datas['transactions'], function ($item) use ($codeData) {
                    // Tìm transaction có Description chứa transaction_code từ Redis
                    if (!isset($item['Description'])) {
                        return false;
                    }
                    // Tìm kiếm không phân biệt hoa thường
                    return stripos($item['Description'], $codeData) !== false;
                });


                $filtered = array_values($filtered);


                       
                foreach ($filtered as $transaction) {
                    $userId = null;

                    $Reference = $transaction['Reference'];

                    $lichsu = BankAuto::where('tid', $Reference)->first();


                    if (!$lichsu) {
                        $description = $transaction['Description'];

                        // Xác định partner dựa trên $codeData
                        $partner = 'smm'; // Mặc định


                        // Tách chuỗi theo format: 152 + YYYYMMDD (8 số) + random string + user_id
                        // Ví dụ: 15220251203bfv0541
                        // - 152: prefix mặc định
                        // - 20251203: năm tháng ngày (YYYYMMDD)
                        // - bfv054: random string (bắt đầu bằng chữ, có thể có số)
                        // - 1: user_id (số cuối cùng)
                        $dateStr = null;
                        $randomStr = null;

                        // Tìm và cắt phần chứa $codeData trong description
                        // Ví dụ description: "5337ibt1kjhs7ese.15220251203bfv0541 ft25337367548210..."
                        // Cần cắt lấy: "15220251203bfv0541" (phần chứa $codeData)
                        $textToParse = null;

                        // Tìm tất cả các pattern 152\d{8}.{6}\d+ trong description
                        if (preg_match_all('/SMM\d{8}.{6}\d+/', $description, $allMatches)) {
                            // Tìm pattern nào chứa $codeData
                            foreach ($allMatches[0] as $match) {
                                if (stripos($match, $codeData) !== false) {
                                    $textToParse = $match;
                                    break;
                                }
                            }
                        }

                        // Nếu không tìm thấy trong description, dùng $codeData trực tiếp
                        if (empty($textToParse)) {
                            $textToParse = $codeData;
                        }

                        // Tách chuỗi: 152 + YYYYMMDD (8 số) + random string (6 ký tự) + user_id (số cuối)
                        // Pattern: 152 + 8 số (date) + 6 ký tự (random) + số cuối (user_id)
                        if (preg_match('/SMM(\d{8})(.{6})(\d+)/', $textToParse, $matches)) {
                            $dateStr = $matches[1]; // YYYYMMDD: 20251203
                            $randomStr = $matches[2]; // Random string (6 ký tự): bfv054
                            $userId = $matches[3]; // User ID (số cuối): 1
                        }


                        // Log để debug
                        if (isset($userId)) {
                            $this->info("📅 Date: {$dateStr}, 🎲 Random: {$randomStr}, 👤 User ID: {$userId}");
                        }

                        $amout = (int)($transaction['CD'] . filter_var($transaction['Amount'], FILTER_SANITIZE_NUMBER_INT));


                        // Partner type
                        $partnerType = $partner;

                        $bankauto = [
                            'tid'         => $Reference,
                            'description' => $description,
                            'date'        => $transaction['TransactionDate'],
                            'data'        => json_encode($transaction),
                            'amount'      => $amout,
                            'type'        => 'bank'
                        ];

                        $bank_auto =   BankAuto::create($bankauto);

                        DB::beginTransaction();


                        if ($userId) {
                            $bankauto['user_id'] = $userId;

                            if ($bankauto['amount'] > 0) {
                                $usernaptien = User::find($userId);



                                if ($usernaptien) {
                                    $str_date = substr($transaction['TransactionDate'], 6, 4) . '-' . substr($transaction['TransactionDate'], 3, 2) . '-' . substr($transaction['TransactionDate'], 0, 2) . ' ' . substr($transaction['PCTime'], 0, 2) . ':' . substr($transaction['PCTime'], 2, 2) . ':' . substr($transaction['PCTime'], 4, 2);
                                    //$str_date = substr($transaction['TransactionDate'], 6, 4).'-'.substr($transaction['TransactionDate'], 3, 2).'-'.substr($transaction['TransactionDate'], 0, 2).' '.'00:00:00';


                                    $dongtien = [
                                        'balance_before'  => (int)$usernaptien['balance'],
                                        'amount'          => $amout,
                                        'balance_after'   => (int)$usernaptien['balance'] + $amout,
                                        'thoigian'        => date('Y-m-d H:i:s', strtotime($str_date)),
                                        'noidung'         => 'Nạp tiền thành công.',
                                        'user_id'         => $userId,
                                        'type'            => Dongtien::TYPE_DEPOSIT,
                                        'payment_method'  => 'bank',
                                        'payment_ref'     => $Reference,
                                        'datas'           => json_encode($bankauto),
                                        'bank_auto_id'    => $bank_auto->id,
                                    ];


                                    Dongtien::create($dongtien);
                                    $usernaptien->balance      = $usernaptien['balance'] + $amout;
                                    // $usernaptien->sotiennap = $usernaptien['sotiennap'] + $amout;
                                    $usernaptien->save();

                                    // Định dạng số tiền
                                    $formattedAmount = number_format($amout, 0, ',', '.') . ' VND';

                                    // Thời gian hiện tại (giờ VN)
                                    $time = Carbon::now('Asia/Ho_Chi_Minh')->format('d/m/Y H:i:s');

                                    // Thêm emoji cho đẹp
                                    $message = "💰 Thông báo Nạp tiền\n"
                                        . "👤 Tài khoản: {$usernaptien->name}\n"
                                        . "💵 Số tiền: {$formattedAmount}\n"
                                        . "⏰ Thời gian: {$time}";

                                    // Gửi với parse_mode Markdown để in đậm
                                    // TelegramHelper::sendNotifyNapTienSystem($message, 'Thông báo nhận tiền', null);
                                }
                            };
                        }

                        DB::commit();
                        $this->info('them giao dich' . $Reference);

                        // Xóa mã giao dịch trong Redis và Database sau khi xử lý thành công
                        try {
                            // Xóa trong Database (code_transactions) trước
                            $deletedFromDb = false;

                            // Nếu không xóa được theo ID, thử xóa theo transaction_code
                            if (!$deletedFromDb && isset($data['transaction_code'])) {
                                $codeTransaction = CodeTransaction::where('transaction_code', $data['transaction_code'])->first();
                                if ($codeTransaction) {
                                    $codeTransaction->delete();
                                    $deletedFromDb = true;
                                    $this->info("✅ Đã xóa mã giao dịch trong Database (Code: {$data['transaction_code']})");
                                }
                            }

                            if (!$deletedFromDb) {
                                $this->warn("⚠️  Không tìm thấy CodeTransaction để xóa (ID: " . ($data['id'] ?? 'N/A') . ", Code: " . ($data['transaction_code'] ?? 'N/A') . ")");
                            }

                            // Xóa trong Redis
                            RedisHelper::del($key, RedisHelper::REDIS_CODE_TRANSACTIONS);
                            $this->info("✅ Đã xóa mã giao dịch trong Redis: {$key}");
                        } catch (\Exception $e) {
                            $this->warn("⚠️  Lỗi khi xóa mã giao dịch: " . $e->getMessage());
                            logger()->warning('Failed to delete code transaction', [
                                'key' => $key,
                                'data' => $data,
                                'error' => $e->getMessage()
                            ]);
                        }
                    }
                }
            }
        }
    }
}
