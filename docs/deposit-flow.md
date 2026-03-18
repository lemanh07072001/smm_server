# Luồng xử lý nạp tiền (Deposit Flow)

## Tổng quan

Hệ thống hỗ trợ nạp tiền tự động qua Pay2s webhook. User tạo mã QR trên frontend, chuyển khoản đúng nội dung và số tiền, hệ thống tự động cộng tiền vào tài khoản.

---

## 1. User tạo QR thanh toán

```
Frontend (DepositPageClient)
  → POST /api/user/deposits          (Next.js API route)
  → POST /api/deposits/pending        (Laravel backend)
  → Tạo bản ghi BankAuto:
      status     = pending
      expires_at = now() + 5 phút
      amount     = số tiền user nhập
      description = deposit_code của user (NAP + 6 số)
```

**Kết quả:** Bản ghi `bank_auto` với `status=pending` và `expires_at` được tạo.

---

## 2. Floating Toast theo dõi giao dịch

- Frontend dispatch event `deposit:created` → `PendingDepositToast` hiện ngay
- Toast poll `/api/deposits/{id}` mỗi 5 giây
- Countdown đếm ngược đến `expires_at`
- Nếu `status = success/expired/cancelled` → toast ẩn

---

## 3. Pay2s gửi Webhook

```
POST /api/webhook/pay2s
Headers:
  Authorization: Bearer {pay2s_webhook_token}

Body:
{
  "transactions": [{
    "id": 26191024,
    "gateway": "VCB",
    "transactionDate": "2026-03-04 11:42:10",
    "transferType": "IN",
    "transferAmount": 50000,
    "content": "NAP123456 chuyen tien"
  }]
}
```

---

## 4. Xử lý Webhook (Pay2sController → DepositService)

### Bước 4.1 — Xác thực token
```
Lấy pay2s_webhook_token từ bảng settings
hash_equals('Bearer ' + token, Authorization header)
  → Không khớp: trả 401, ghi Log::warning
  → Khớp: tiếp tục
```

### Bước 4.2 — Validate transaction
```
transferType != 'IN'  → bỏ qua (tiền ra)
amount <= 0           → bỏ qua, ghi warning
id null               → bỏ qua, ghi warning
transactionId = 'P2S_' + id
```

### Bước 4.3 — Tìm user từ nội dung
```
Regex: /NAP\d{6}/ trong content (uppercase)
  → Tìm user có deposit_code khớp trong bảng users

Không tìm thấy user:
  → isDuplicateTransaction(tid)? → bỏ qua nếu đã tồn tại
  → savePendingDeposit() → status=pending, user_id=null
  → mlog: no_user_found (warning)
  → Dừng, chờ admin gán user thủ công
```

### Bước 4.4 — processDeposit() [trong DB transaction]

```
DB::beginTransaction()

┌─ Kiểm tra trùng tid (lockForUpdate)
│   → Có: tạo bản ghi _DUP_xxx, status=pending_duplicate
│         mlog: duplicate_tid (warning)
│         DB::commit() → return
│
├─ Tìm user (User::find)
│   → Không có: DB::rollBack() → return
│
├─ Tìm pending record còn hạn của user (lockForUpdate)
│   expires_at > now(), status=pending
│
│  [Có pending record]
│   ├─ amount KHÔNG khớp:
│   │   → update pending: status=pending_duplicate, ghi note lệch amount
│   │   → DB::commit()
│   │   → mlog: pending_found + amount_mismatch (warning)
│   │   → Chờ admin duyệt thủ công → return
│   │
│   └─ amount KHỚP:
│       → update pending: status=success, tid=transactionId, expires_at=null
│       → bankAuto = pendingRecord
│
│  [Không có pending record]
│   → Tạo bản ghi mới: status=pending_duplicate, ghi note không có QR
│   → DB::commit()
│   → mlog: no_pending (warning)
│   → Chờ admin duyệt thủ công → return
│
├─ Tạo Dongtien (cộng tiền vào balance user)
├─ broadcast(DepositSuccess) → realtime notify frontend
├─ creditAffiliateCommission() (nếu có referrer)
│
└─ DB::commit()
   → mlog: pending_matched + deposit_success (success)
```

---

## 5. Kết quả các trường hợp

| Tình huống | Status BankAuto | Cộng tiền | Xử lý |
|---|---|---|---|
| Amount khớp, có pending | `success` | ✅ Tự động | - |
| Trùng tid | `pending_duplicate` | ❌ | Admin duyệt |
| Amount lệch | `pending_duplicate` | ❌ | Admin duyệt |
| Không có pending QR | `pending_duplicate` | ❌ | Admin duyệt |
| Không tìm thấy user | `pending` | ❌ | Admin gán user + duyệt |

---

## 6. Admin duyệt thủ công (approveDeposit)

```
Kiểm tra status ∈ [pending, pending_duplicate]
Kiểm tra user_id != null
Kiểm tra Dongtien chưa có bank_auto_id này (chống double approve)
  → update status = success
  → Tạo Dongtien (cộng tiền)
  → broadcast(DepositSuccess)
  → creditAffiliateCommission()
  → mlog: admin_approved (success)
```

---

## 7. Scheduler — Expire Deposits (mỗi phút)

```
php artisan deposits:expire

Bước 1 — markExpired:
  BankAuto: status=pending, expires_at <= now()
  → update status = expired
  → mlog: mark_expired (warning)

Bước 2 — deleteExpired:
  BankAuto: status=expired, tid IS NULL
  → DELETE (user tạo QR nhưng không chuyển khoản)

Giữ lại: status=expired có tid (Pay2s đã xử lý nhưng lỗi logic)
```

---

## 8. MongoDB Logs (deposit_logs collection)

| step | status | Khi nào |
|---|---|---|
| `no_user_found` | warning | Không tìm thấy user từ nội dung CK |
| `duplicate_tid` | warning | Trùng transaction ID từ Pay2s |
| `pending_found` | success | Tìm thấy bản ghi pending của user |
| `amount_mismatch` | warning | Có pending nhưng số tiền lệch |
| `pending_matched` | success | Pending + amount khớp, update success |
| `no_pending` | warning | Không có QR pending, lưu chờ admin |
| `deposit_success` | success | Cộng tiền thành công |
| `admin_approved` | success | Admin duyệt thủ công |
| `mark_expired` | warning | Scheduler đánh dấu expired |

---

## 9. Security

- **Token xác thực:** `hash_equals()` chống timing attack
- **Race condition:** `lockForUpdate()` trên tid check và pending record
- **Double approve:** Kiểm tra `Dongtien.bank_auto_id` trước khi cộng tiền
- **Double commission:** Kiểm tra `AffiliateCommission.deposit_id` trước khi cộng hoa hồng
- **Self-referral:** Chặn user tự giới thiệu bản thân
- **mlog ngoài transaction:** Tránh MongoDB exception rollback MySQL

---

## 10. Sơ đồ tóm tắt

```
User tạo QR
    ↓
[bank_auto: pending, expires_at=+5m]
    ↓
Pay2s gửi webhook
    ↓
Verify token → Validate → Tìm user
    ↓
processDeposit()
    ├── Trùng tid? → pending_duplicate → Admin
    ├── Có pending + amount khớp? → success → Cộng tiền ✅
    ├── Có pending + amount lệch? → pending_duplicate → Admin
    └── Không có pending? → pending_duplicate → Admin

Scheduler (mỗi phút)
    └── pending hết hạn → expired → xoá nếu không có tid
```
