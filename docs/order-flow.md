# Luồng hoạt động Order

## Tổng quan

```
User gọi API
    ↓
OrderController::addOrder()
    ↓  (ngay sau commit DB)
Push vào Redis Queue
    ↓
PlaceOrder add-priority (while loop)
    ↓ success               ↓ fail (retry < 5)          ↓ fail (retry >= 5)
status=in_progress      retry_after +2s               status=failed
provider_order_id set   push lại queue                hoàn tiền user
                                                       Telegram alert
    ↓
PlaceOrder status (mỗi 1 phút)
    ↓
Gọi batch API provider kiểm tra trạng thái
    ↓
Cập nhật status: completed / partial / failed
Hoàn tiền nếu partial/failed
```

---

## Chi tiết từng bước

### 1. Tạo Order — `OrderController::addOrder()`

**File:** `app/Http/Controllers/Api/OrderController.php`

1. Validate request (service_id, link, quantity, ...)
2. Kiểm tra service đang active, provider đang hoạt động
3. Tính `charge_amount`, `cost_amount`, `profit_amount`
4. `DB::beginTransaction()`
   - Lock user row (`lockForUpdate`) để tránh race condition trừ tiền
   - Kiểm tra số dư
   - Tạo bản ghi `Order` với `status=pending`
   - Tạo transaction `Dongtien` trừ tiền user
5. `DB::commit()`
6. Ghi `OrderActivityLog` (type: `order_created`) → Redis activity queue
7. **Push thẳng vào Redis queue** (`pushOrderToQueue`) — không chờ `scan`
   - Ghi `OrderActivityLog` (type: `order_queued`)
   - Nếu Redis lỗi: silent fail, `scan` sẽ recovery sau 2 phút
8. Trả về response 201

---

### 2. Queue Redis

**Key:** `key_id_redis_order_priority_0`
**Connection:** default Redis

Mỗi item trong queue là JSON: `{"id": <order_id>}`
Khi retry: `{"id": <order_id>, "retry_count": N, "retry_after": <timestamp>}`

**Priority:**
- `is_priority = 0` (cao) → `LPUSH` (đầu queue)
- `is_priority = 1` (thường) → `RPUSH` (cuối queue)

---

### 3. Xử lý Order — `PlaceOrder add-priority`

**File:** `app/Console/Commands/PlaceOrder.php`
**Chạy:** `php artisan order_place add-priority`
**Vòng lặp:** `while(true)`, tự thoát sau **600 giây**, supervisor/schedule restart lại

**Flow mỗi vòng (100ms/tick):**

```
RPOP key_id_redis_order_priority_0
    ↓ null → sleep 100ms, tiếp tục
    ↓ có data
Decode JSON → lấy order_id
    ↓
Kiểm tra retry_after → nếu chưa đến giờ: RPUSH lại cuối queue, skip
    ↓
Atomic lock: SET place_order_lock:order:{id} NX EX 120
    ↓ fail → process khác đang xử lý, skip
    ↓ success → ta own order này
Truy vấn DB: status=pending AND provider_order_id IS NULL
    ↓ không tồn tại → release lock, skip (đã xử lý rồi)
    ↓ tồn tại
callProviderApi($order)
    ↓ success
        applySuccessUpdate: provider_order_id, status=in_progress
        release lock
    ↓ skip_retry (provider bị tắt)
        giữ nguyên status=pending
        release lock (scan sẽ push lại sau)
    ↓ fail
        retry_count++
        if retry >= 5:
            applyFailedUpdate: status=failed, hoàn tiền, Telegram alert
            release lock
        else:
            lưu retry_count vào DB
            RPUSH lại queue với retry_after = now+2s
            release lock
```

---

### 4. Fallback/Recovery — `PlaceOrder scan`

**File:** `app/Console/Commands/PlaceOrder.php`
**Chạy:** `php artisan order_place scan` (schedule: mỗi 1 phút)
**Mục đích:** Recovery cho các order bị sót (Redis lỗi lúc tạo, server restart, ...)

```
Query DB (cursor streaming): SELECT id, is_priority   ← chỉ 2 cột, tiết kiệm memory
          WHERE status=pending AND provider_order_id IS NULL
          AND (retry_count IS NULL OR retry_count < RETRY_COUNT)
          AND created_at <= now() - 2 phút  ← tránh race với Controller
          ORDER BY id ASC
    ↓
Chunk 1000 orders (array_values để index 0,1,2...)
    ↓
1 Redis pipeline: check lock + scan_queued cùng lúc (2000 EXISTS, 1 round-trip)
    EXISTS place_order_lock:order:{id}   # x1000
    EXISTS scan_queued:order:{id}        # x1000
    ↓
Foreach order:
    lock || scan_queued → skip
    is_priority=0 → priorityPush[] (lpush đầu queue, xử lý trước)
    else           → normalPush[]  (rpush cuối queue, xử lý sau)
    scanQueuedKeys[] = 'scan_queued:order:{id}'
    ↓
1 Redis pipeline: push queue + setex scan_queued cùng lúc
    lpush KEY_PRIORITY_0 ...priorityPush
    rpush KEY_PRIORITY_0 ...normalPush
    setex scan_queued:order:{id} 300 1   # TTL 5 phút, mỗi key
```

**scan_queued key (TTL 300s):** Đánh dấu order đã được push, tránh scan push trùng mỗi phút.

**Tối ưu cho 1000+ orders:**
| Thay đổi | Lý do |
|---|---|
| `SELECT id, is_priority` thay vì `SELECT *` | Giảm ~10x memory mỗi row cursor |
| Chunk 1000 thay vì 500 | Giảm 50% số pipeline round-trips với Redis |
| Track `$scanQueuedKeys` (string) thay vì `$toPushOrders` (object) | Nhẹ hơn trong memory |
| `array_values()` ở ngoài closure | Tránh re-index thừa |

> **Lưu ý:** `scan` không acquire lock. Lock chỉ được set bởi `add-priority` khi thực sự xử lý. Duplicate trong queue an toàn vì `add-priority` luôn kiểm tra lại DB trước khi gọi provider.

---

### 5. Kiểm tra trạng thái — `PlaceOrder status`

**File:** `app/Console/Commands/PlaceOrder.php`
**Chạy:** `php artisan order_place status` (schedule: mỗi 1 phút)

```
Query DB: status IN (in_progress, pending) AND provider_order_id IS NOT NULL
    ↓
Chunk 500, group theo provider
    ↓ mỗi provider
        Chia batch 100 order mỗi lần gọi API
        Circuit breaker: 10 batch lỗi liên tiếp → ngắt provider + Telegram alert
        Gọi providerService->getOrderStatus([provider_order_ids])
        Parse response → map status
        Bulk update DB theo nhóm status giống nhau
        Hoàn tiền nếu failed (100%) hoặc partial (theo tỉ lệ remains/quantity)
```

**Status mapping từ provider:**

| Provider status | Internal status |
|---|---|
| Completed | `completed` |
| In progress / In Progress | `in_progress` |
| Partial | `partial` |
| Canceled | `canceled` |
| Refilling | `refilling` |
| Pending | `pending` |

---

### 6. Kiểm tra hoàn tiền — `PlaceOrder refund-check`

**Chạy:** `php artisan order_place refund-check` (schedule: mỗi 1 phút)

Tương tự `status` nhưng chỉ query `status=processing` (đơn đang chờ hoàn từ provider sau khi hủy).

---

### 7. Activity Logging

**Flow:**

```
OrderActivityLogger::for($orderId)->...->log()
    ↓
LPUSH activity_logs_queue (Redis connection: activity_logs_redis)
    ↓ Redis lỗi → Log::warning() vào laravel.log, không crash flow chính
    ↓
SaveActivityLog command (schedule: mỗi 1 phút, timeout 55s)
    ↓
RPOP từ Redis → INSERT vào MongoDB collection order_activity_logs
    ↓ Mongo lỗi → RPUSH lại Redis để retry
    ↓
Auto-delete sau 30 ngày (TTL index)
```

**API xem log:** `GET /api/orders/{orderId}/logs`
- Admin: xem được mọi order
- User: chỉ xem được order của mình

---

### 8. Schedule tổng hợp

| Command | Interval | Mục đích |
|---|---|---|
| `order_place scan` | 1 phút | Recovery order bị sót |
| `order_place status` | 1 phút | Cập nhật trạng thái từ provider |
| `order_place refund-check` | 1 phút | Xử lý hoàn tiền đơn đang chờ hoàn |
| `activity_log:save` | 1 phút | Redis → MongoDB activity logs |
| `report:order` | 10 phút | Thống kê đơn hàng |
| `report:user-financial` | 10 phút | Thống kê tài chính user |
| `checkbank` | 1 phút | Kiểm tra nạp tiền ngân hàng |

---

### 9. Hoàn tiền

**Khi nào hoàn:**

| Tình huống | Loại hoàn | Số tiền |
|---|---|---|
| Provider fail sau 5 lần retry | 100% | `charge_amount` |
| Provider trả về `failed` | 100% | `charge_amount` |
| Provider trả về `partial` | Theo tỉ lệ | `remains / quantity * charge_amount` |
| User hủy đơn (chưa gửi provider) | 100% | `charge_amount` |
| User hủy đơn (đã gửi provider) | Chờ provider xác nhận | — |

---

### 10. Các Redis key quan trọng

| Key | Mục đích | Connection |
|---|---|---|
| `key_id_redis_order_priority_0` | Queue order chờ gửi provider | default |
| `place_order_lock:order:{id}` | Atomic lock khi xử lý order (TTL 120s) | default |
| `scan_queued:order:{id}` | Đã được scan push, tránh duplicate (TTL 300s) | default |
| `activity_logs_queue` | Queue activity logs chờ lưu MongoDB | activity_logs_redis |
| `key_id_redis_order` | Cache order data (OrderHelper) | order_web_redis |
