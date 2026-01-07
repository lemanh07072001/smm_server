# GenerateOrderReport Command

## Tổng quan

Command `php artisan report:order` dùng để tạo báo cáo thống kê đơn hàng theo ngày. Sử dụng phương pháp **incremental** (chỉ xử lý đơn hàng mới hoặc thay đổi) thay vì tính lại toàn bộ.

## Cách hoạt động

### 1. Query đơn hàng cần xử lý

```php
$orders = Order::where(function ($q) {
    $q->whereNull('scanned_at')
      ->orWhereColumn('updated_at', '>', 'scanned_at');
})->cursor();
```

Chỉ lấy các đơn hàng:
- **Chưa từng được scan** (`scanned_at IS NULL`) - đơn hàng mới
- **Đã thay đổi sau lần scan cuối** (`updated_at > scanned_at`) - đơn hàng có cập nhật status

### 2. Tạo Report Key

```php
$dateAt = (int) date('Ymd', strtotime($order->created_at));
$reportKey = md5("{$dateAt}|{$order->user_id}|{$order->service_id}");
```

Mỗi report được định danh bởi tổ hợp:
- `date_at`: Ngày tạo đơn (format YYYYMMDD, ví dụ: 20240127)
- `user_id`: ID người dùng
- `service_id`: ID dịch vụ

**Ví dụ:** User 5 mua service 10 vào ngày 27/01/2024 → `report_key = md5("20240127|5|10")`

### 3. Khởi tạo Report

```php
if (!isset($reports[$reportKey])) {
    $existingReport = ReportOrderDaily::where('report_key', $reportKey)->first();

    if ($existingReport) {
        // Dùng dữ liệu có sẵn từ DB
        $reports[$reportKey] = $existingReport->toArray();
    } else {
        // Tạo mới với giá trị mặc định = 0
        $reports[$reportKey] = [
            'report_key' => $reportKey,
            'date_at' => $dateAt,
            'user_id' => $order->user_id,
            'service_id' => $order->service_id,
            'order_pending' => 0,
            // ... các field khác
        ];
    }
}
```

### 4. Xử lý đơn hàng đã thay đổi status

```php
if ($order->scanned_at !== null) {
    $oldStatus = $order->old_scanned_status;
    if ($oldStatus) {
        // Trừ giá trị cũ
        $reports[$reportKey]["order_{$oldStatus}"]--;
        $reports[$reportKey]['total_quantity'] -= $order->quantity;
        $reports[$reportKey]['total_charge'] -= $order->charge_amount;
        $reports[$reportKey]['total_cost'] -= $order->cost_amount;
        $reports[$reportKey]['total_profit'] -= $order->profit_amount;
        $reports[$reportKey]['total_refund'] -= $order->refund_amount;
    }
}
```

**Giải thích:**
- Nếu đơn hàng đã được scan trước đó (`scanned_at !== null`)
- Và có `old_scanned_status` (status cũ đã lưu)
- → Trừ đi các giá trị đã tính lần trước để tránh đếm trùng

**Ví dụ:**
1. Đơn #123 status = `pending` → lần scan đầu → `order_pending++`
2. Đơn #123 đổi thành `completed` → lần scan sau:
   - Trừ: `order_pending--` (vì `old_scanned_status = pending`)
   - Cộng: `order_completed++` (vì `status = completed`)

### 5. Cộng giá trị mới

```php
$reports[$reportKey]['total_quantity'] += $order->quantity;
$reports[$reportKey]['total_charge'] += $order->charge_amount;
$reports[$reportKey]['total_cost'] += $order->cost_amount;
$reports[$reportKey]['total_profit'] += $order->profit_amount;
$reports[$reportKey]['total_refund'] += $order->refund_amount;

$statusField = "order_{$order->status}";
if (isset($reports[$reportKey][$statusField])) {
    $reports[$reportKey][$statusField]++;
}
```

### 6. Lưu báo cáo vào database

```php
ReportOrderDaily::updateOrCreate(
    ['report_key' => $report['report_key']],
    $report
);
```

Dùng `updateOrCreate`:
- Nếu `report_key` đã tồn tại → Update
- Nếu chưa có → Insert mới

### 7. Đánh dấu đơn hàng đã xử lý

```php
Order::where('id', $orderId)->update([
    'scanned_at' => $now,
    'old_scanned_status' => $status,
]);
```

- `scanned_at`: Thời điểm scan (để lần sau so sánh với `updated_at`)
- `old_scanned_status`: Lưu status hiện tại (để lần sau trừ đi nếu status thay đổi)

## Sơ đồ luồng xử lý

```
┌─────────────────────────────────────────────────────────────────┐
│                    START: php artisan report:order              │
└─────────────────────────────────────────────────────────────────┘
                                  │
                                  ▼
┌─────────────────────────────────────────────────────────────────┐
│  Query orders: scanned_at IS NULL OR updated_at > scanned_at    │
└─────────────────────────────────────────────────────────────────┘
                                  │
                                  ▼
┌─────────────────────────────────────────────────────────────────┐
│                    Có orders cần xử lý?                         │
│                         NO → EXIT                               │
└─────────────────────────────────────────────────────────────────┘
                                  │ YES
                                  ▼
┌─────────────────────────────────────────────────────────────────┐
│                  LOOP: Foreach order                            │
│  ┌───────────────────────────────────────────────────────────┐  │
│  │ 1. Tạo report_key = md5(date|user_id|service_id)          │  │
│  │ 2. Load report từ DB hoặc khởi tạo mới                    │  │
│  │ 3. Nếu order đã scan trước:                               │  │
│  │    → Trừ giá trị cũ (old_scanned_status)                  │  │
│  │ 4. Cộng giá trị mới (status hiện tại)                     │  │
│  │ 5. Lưu order_id + status để update sau                    │  │
│  └───────────────────────────────────────────────────────────┘  │
└─────────────────────────────────────────────────────────────────┘
                                  │
                                  ▼
┌─────────────────────────────────────────────────────────────────┐
│              Lưu reports vào DB (updateOrCreate)                │
└─────────────────────────────────────────────────────────────────┘
                                  │
                                  ▼
┌─────────────────────────────────────────────────────────────────┐
│     Update orders: scanned_at = now, old_scanned_status         │
└─────────────────────────────────────────────────────────────────┘
                                  │
                                  ▼
┌─────────────────────────────────────────────────────────────────┐
│                            DONE                                 │
└─────────────────────────────────────────────────────────────────┘
```

## Các trường trong bảng `report_order_daily`

| Field | Type | Mô tả |
|-------|------|-------|
| `report_key` | VARCHAR(64) | MD5 key duy nhất (date + user_id + service_id) |
| `date_at` | INT | Ngày thống kê (YYYYMMDD) |
| `user_id` | BIGINT | ID người dùng |
| `service_id` | BIGINT | ID dịch vụ |
| `order_pending` | INT | Số đơn chờ xử lý |
| `order_processing` | INT | Số đơn đang xử lý |
| `order_in_progress` | INT | Số đơn đang chạy |
| `order_completed` | INT | Số đơn hoàn thành |
| `order_partial` | INT | Số đơn hoàn thành một phần |
| `order_canceled` | INT | Số đơn đã hủy |
| `order_refunded` | INT | Số đơn đã hoàn tiền |
| `order_failed` | INT | Số đơn thất bại |
| `total_charge` | DECIMAL(18,2) | Tổng tiền thu từ user |
| `total_cost` | DECIMAL(18,2) | Tổng tiền chi cho provider |
| `total_profit` | DECIMAL(18,2) | Tổng lợi nhuận |
| `total_refund` | DECIMAL(18,2) | Tổng tiền hoàn |
| `total_quantity` | INT | Tổng số lượng mua |

## Các trường bổ sung trong bảng `orders`

| Field | Type | Mô tả |
|-------|------|-------|
| `scanned_at` | TIMESTAMP | Thời điểm scan cuối cùng |
| `old_scanned_status` | VARCHAR(20) | Status tại thời điểm scan cuối |

## Scheduler

Command được cấu hình chạy tự động mỗi 5 phút trong `app/Console/Kernel.php`:

```php
$schedule->command('report:order')
    ->runInBackground()
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/order-report.log'));
```

## Ưu điểm của phương pháp Incremental

1. **Hiệu suất cao**: Chỉ xử lý đơn hàng mới/thay đổi, không tính lại toàn bộ
2. **Tiết kiệm tài nguyên**: Sử dụng `cursor()` để xử lý từng record, tránh load toàn bộ vào RAM
3. **Chính xác**: Theo dõi status cũ để cập nhật chính xác khi đơn hàng thay đổi trạng thái
4. **Real-time**: Cập nhật báo cáo gần như real-time (mỗi 5 phút)

## Chạy thủ công

```bash
php artisan report:order
```

Output mẫu:
```
🚀 Bắt đầu thống kê: 10:30:00 27-01-2024
📊 Đang query orders...
🔄 Đang xử lý dữ liệu...
  → Đã xử lý 5000 orders...
  → Đã xử lý 10000 orders...
✅ Kết thúc xử lý: 10:30:15 27-01-2024
📝 Tổng orders cần xử lý: 12500
💾 Đang lưu báo cáo vào database...
..........
📌 Đang cập nhật scanned_at cho 12500 orders...

✅ Hoàn thành! Đã cập nhật 350 báo cáo.
🏁 Kết thúc: 10:30:45 27-01-2024
```
