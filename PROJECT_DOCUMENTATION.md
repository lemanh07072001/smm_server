# SSM Backend - Project Documentation

## Tổng quan

SSM Backend là một ứng dụng Laravel 10 phục vụ hệ thống SMM (Social Media Marketing). Hệ thống cho phép người dùng đặt các dịch vụ tăng tương tác mạng xã hội (like, follow, comment, view...) thông qua các nhà cung cấp bên thứ ba.

---

## 1. Cấu trúc thư mục

```
ssmbackend/
├── app/
│   ├── Console/
│   │   └── Commands/           # Artisan commands
│   │       ├── CheckBank.php
│   │       ├── CheckOrderStatus.php
│   │       ├── PlaceOrder.php
│   │       └── SaveActivityLog.php
│   ├── Helpers/                # Helper functions
│   │   ├── CurlHelper.php
│   │   ├── OrderActivityLogger.php
│   │   ├── OrderHelper.php
│   │   ├── RedisHelper.php
│   │   └── TelegramHelper.php
│   ├── Http/
│   │   ├── Controllers/Api/    # API Controllers
│   │   ├── Middleware/         # HTTP Middleware
│   │   └── Requests/           # Form Request Validation
│   ├── Models/                 # Eloquent Models
│   ├── Providers/              # Service Providers
│   └── Services/
│       └── Providers/          # External Provider Implementations
├── config/                     # Configuration files
├── database/
│   └── migrations/             # Database migrations
├── routes/
│   ├── api.php                 # API routes
│   ├── web.php                 # Web routes
│   └── channels.php            # Broadcasting channels
└── storage/
```

---

## 2. Models & Relationships

### User
- **Bảng:** `users`
- **Fields:** id, name, email, password, role, balance, discount, api_key, is_active
- **Role:** 0 = Admin, 1 = User
- **Relationships:** hasMany(Order)

### Category
- **Bảng:** `categories`
- **Fields:** id, name, slug, description, image, sort_order, is_active
- **Computed:** image_url (từ Storage)

### CategoryGroup
- **Bảng:** `category_groups`
- **Fields:** id, name, icon, sort_order, is_active, group_id, description
- **Relationships:** hasMany(Service)

### Service
- **Bảng:** `services`
- **Fields:** id, category_group_id, provider_service_id, name, description, sell_rate, min_quantity, max_quantity, sort_order, priority, is_active, allow_multiple_reactions, reaction_types
- **Priority:** 0=Very Slow, 1=Slow, 2=Normal, 3=Fast, 4=Very Fast
- **Platform:** Facebook, Tiktok, Twitter, Instagram, Youtube, Zalo
- **Relationships:** belongsTo(CategoryGroup), belongsTo(ProviderService), hasMany(Order)

### Provider
- **Bảng:** `providers`
- **Fields:** id, name, code, api_url, api_key, balance, is_active, notes, image
- **Relationships:** hasMany(ProviderService)

### ProviderService
- **Bảng:** `provider_services`
- **Fields:** id, provider_id, provider_service_code, name, category_name, cost_rate, min_quantity, max_quantity, is_active, reaction_types
- **Relationships:** belongsTo(Provider), hasMany(Service), hasMany(Order)

### Order
- **Bảng:** `orders`
- **Fields:** id, user_id, service_id, provider_service_id, provider_order_id, link, quantity, comments, start_count, remains, status, cost_rate, sell_rate, charge_amount, cost_amount, profit_amount, refund_amount, final_charge, final_cost, final_profit, is_finalized, error_message
- **Status:** pending, processing, in_progress, completed, partial, canceled, refunded, failed
- **Relationships:** belongsTo(User), belongsTo(Service), belongsTo(ProviderService)

### BankAuto
- **Bảng:** `bank_auto`
- **Fields:** id, tid, description, date, data, amount, user_id, transaction_type, type, status
- **Type:** bank, binance
- **Relationships:** belongsTo(User), hasMany(Dongtien)

### Dongtien (Lịch sử giao dịch)
- **Bảng:** `dongtien`
- **Fields:** id, balance_before, amount, balance_after, thoigian, noidung, user_id, order_id, payment_method, type, payment_ref, datas, bank_auto_id
- **Type:** deposit, charge, refund, adjustment
- **Relationships:** belongsTo(User), belongsTo(Order), belongsTo(BankAuto)

### CodeTransaction
- **Bảng:** `code_transactions`
- **Fields:** id, transaction_code, timestamps
- **Mục đích:** Lưu mã giao dịch để match với bank

### OrderActivityLog (MongoDB)
- **Collection:** order_activity_logs
- **Fields:** order_id, user_id, provider_code, provider_order_id, type, level, message, request_data, response_data, metadata, duration_ms, created_at
- **Mục đích:** Log chi tiết hoạt động đơn hàng

---

## 3. API Endpoints

### Base URL: `/api`

### Public Routes (Không cần auth)
| Method | Endpoint | Mô tả |
|--------|----------|-------|
| POST | /register | Đăng ký tài khoản |
| POST | /login | Đăng nhập |
| GET | /categories/all | Lấy tất cả danh mục |
| GET | /category-groups/all | Lấy tất cả nhóm danh mục |
| GET | /services/all | Lấy tất cả dịch vụ |
| GET | /services/form-types | Lấy các loại form |
| POST | /get-providers | Lấy danh sách providers |

### Protected Routes (Cần auth:sanctum)

#### Authentication
| Method | Endpoint | Mô tả |
|--------|----------|-------|
| GET | /user | Lấy thông tin user hiện tại |
| POST | /logout | Đăng xuất |
| GET | /profile | Lấy profile |

#### Categories
| Method | Endpoint | Mô tả |
|--------|----------|-------|
| GET | /categories | Danh sách (phân trang) |
| POST | /categories | Tạo mới |
| GET | /categories/{id} | Chi tiết |
| POST | /categories/{id} | Cập nhật |
| DELETE | /categories/{id} | Xóa |
| POST | /categories/delete-multiple | Xóa nhiều |

#### Category Groups
| Method | Endpoint | Mô tả |
|--------|----------|-------|
| GET | /category-groups | Danh sách |
| POST | /category-groups | Tạo mới |
| GET | /category-groups/{id} | Chi tiết |
| POST | /category-groups/{id} | Cập nhật |
| DELETE | /category-groups/{id} | Xóa |
| POST | /category-groups/delete-multiple | Xóa nhiều |

#### Providers
| Method | Endpoint | Mô tả |
|--------|----------|-------|
| GET | /providers | Danh sách |
| POST | /providers | Tạo mới |
| GET | /providers/{id} | Chi tiết |
| POST | /providers/{id} | Cập nhật |
| DELETE | /providers/{id} | Xóa |
| POST | /providers/delete-multiple | Xóa nhiều |

#### Provider Services
| Method | Endpoint | Mô tả |
|--------|----------|-------|
| GET | /provider-services | Danh sách |
| POST | /provider-services | Tạo mới |
| GET | /provider-services/all | Lấy tất cả (active) |
| GET | /provider-services/{id} | Chi tiết |
| POST | /provider-services/{id} | Cập nhật |
| DELETE | /provider-services/{id} | Xóa |
| POST | /provider-services/delete-multiple | Xóa nhiều |

#### Services
| Method | Endpoint | Mô tả |
|--------|----------|-------|
| GET | /services | Danh sách |
| POST | /services | Tạo mới |
| GET | /services/platforms | Lấy danh sách platforms |
| GET | /services/{id} | Chi tiết |
| POST | /services/{id} | Cập nhật |
| DELETE | /services/{id} | Xóa |
| POST | /services/delete-multiple | Xóa nhiều |

#### Users
| Method | Endpoint | Mô tả |
|--------|----------|-------|
| GET | /users | Danh sách |
| POST | /users | Tạo mới |
| GET | /users/{id} | Chi tiết |
| POST | /users/{id} | Cập nhật |
| DELETE | /users/{id} | Xóa |
| POST | /users/delete-multiple | Xóa nhiều |
| POST | /users/{id}/reset-password | Reset mật khẩu |
| POST | /users/{id}/generate-api-key | Tạo API key |

#### Orders
| Method | Endpoint | Mô tả |
|--------|----------|-------|
| GET | /orders | Danh sách (có filter) |
| GET | /orders/user/{userId} | Đơn hàng của user |
| POST | /add-order | Tạo đơn hàng mới |

#### Code Transactions
| Method | Endpoint | Mô tả |
|--------|----------|-------|
| POST | /code-transactions | Tạo mã giao dịch |

---

## 4. Artisan Commands

### checkbank
```bash
php artisan checkbank
```
- **Chạy:** Mỗi phút (scheduled)
- **Chức năng:** Kiểm tra giao dịch ngân hàng từ API, match với mã giao dịch trong Redis, tạo nạp tiền tự động
- **Luồng xử lý:**
  1. Fetch giao dịch từ `https://bank.minsoftware.xyz`
  2. Lấy tất cả mã giao dịch từ Redis
  3. Match description với pattern `smm{YYYYMMDD}{random6}{user_id}`
  4. Tạo BankAuto record
  5. Nếu có user_id và amount > 0: Tạo Dongtien, cộng tiền cho user
  6. Xóa mã đã xử lý khỏi Redis và DB

### order:check-status
```bash
php artisan order:check-status
```
- **Chạy:** Mỗi 5 phút (scheduled)
- **Chức năng:** Kiểm tra trạng thái đơn hàng từ providers
- **Luồng xử lý:**
  1. Query orders với status: pending, processing, in_progress
  2. Gọi API provider để lấy status
  3. Cập nhật order với start_count, remains, status

### order_place
```bash
php artisan order_place
```
- **Chức năng:** Xử lý đơn hàng từ Redis queue, gửi đến providers
- **Luồng xử lý:**
  1. Pop order từ Redis queue
  2. Validate order và status
  3. Gửi request đến provider API
  4. Log activity
  5. Cập nhật order với provider_order_id và status
  6. Nếu lỗi: Cập nhật failed, gửi thông báo Telegram

### activity_log:save
```bash
php artisan activity_log:save
```
- **Chức năng:** Chuyển activity logs từ Redis sang MongoDB
- **Luồng xử lý:**
  1. Pop logs từ Redis queue
  2. Lưu vào MongoDB (OrderActivityLog)
  3. Nếu lỗi: Re-queue để retry

---

## 5. Helpers

### RedisHelper
- Quản lý các Redis connections
- Connections: default, code_transactions_redis, order_web_redis, activity_logs_redis
- Methods: lpush, rpop, set, get, del, hset, hdel, hget, exists, lrange, llen, mget

### OrderHelper
- `saveOrderToRedis(Order)` - Lưu order vào Redis queue để xử lý

### OrderActivityLogger
- Fluent interface để log activities
- Methods: for(), user(), provider(), orderCreated(), orderQueued(), providerRequest(), providerResponse(), orderFailed(), orderCompleted()...

### TelegramHelper
- `sendNotifySystem()` - Gửi thông báo hệ thống
- `sendNotifyNapTienSystem()` - Gửi thông báo nạp tiền
- `sendNotifyErrorSystem()` - Gửi thông báo lỗi

### CurlHelper
- `curl(options)` - Wrapper cho HTTP GET requests

---

## 6. Service Providers (External APIs)

### Provider Architecture
```
ProviderInterface
    └── BaseProvider (abstract)
            ├── SmmPanelProvider
            └── TraoDoiTuongTacProvider
```

### ProviderFactory
- Map provider code với provider class
- Supported: 'trao_doi_tuong_tac', 'smm_panel'

### SmmPanelProvider
- API endpoint: `{api_url}/api/v2`
- Actions: add (đặt order), status (check status)

### TraoDoiTuongTacProvider
- API endpoint: `{api_url}/api/v3`
- Hỗ trợ comments với newlines

---

## 7. Database Configuration

### MySQL
- Database chính cho tất cả models

### MongoDB
- Database: ssm_logs
- Collection: order_activity_logs
- Mục đích: Lưu activity logs

### Redis Databases
| DB | Connection | Mục đích |
|----|------------|----------|
| 0 | default | General cache |
| 1 | cache | Cache store |
| 2 | code_transactions_redis | Mã giao dịch nạp tiền |
| 2 | order_web_redis | Order queue |
| 3 | activity_logs_redis | Activity logs queue |

---

## 8. Luồng xử lý chính

### Đặt đơn hàng (Order Flow)
```
1. User gọi POST /add-order
2. Validate request (service, quantity, link...)
3. Tính toán: chargeAmount = sellRate * quantity
4. Kiểm tra balance user
5. Trừ tiền user
6. Tạo Order (status = pending)
7. Lưu order vào Redis queue
8. Return order + new balance

9. [Background] order_place command:
   - Pop order từ Redis
   - Gửi đến provider API
   - Cập nhật provider_order_id
   - Cập nhật status

10. [Scheduled] order:check-status:
    - Check status từ provider
    - Cập nhật start_count, remains, status
```

### Nạp tiền tự động (Bank Auto Flow)
```
1. User tạo mã giao dịch: POST /code-transactions
2. Mã được lưu vào Redis với TTL 30 ngày
3. User chuyển khoản với nội dung chứa mã

4. [Scheduled - mỗi phút] checkbank command:
   - Fetch giao dịch từ Banking API
   - Match description với mã trong Redis
   - Parse user_id từ pattern: smm{date}{random}{user_id}
   - Tạo BankAuto record
   - Tạo Dongtien record
   - Cộng tiền cho user
   - Xóa mã đã dùng
```

---

## 9. Authentication

- **Method:** Laravel Sanctum (Token-based)
- **Login:** Trả về personal access token
- **Protected routes:** Sử dụng middleware `auth:sanctum`
- **API Key:** User có thể generate 64-char API key

---

## 10. Scheduling

**Console/Kernel.php:**
```php
$schedule->command('checkbank')->everyMinute();
$schedule->command('order:check-status')->everyFiveMinutes();
```

**Cron job trên server:**
```bash
* * * * * cd /var/www/smm_server && php artisan schedule:run >> /dev/null 2>&1
```

---

## 11. External Integrations

### Banking API
- URL: `https://bank.minsoftware.xyz/banking/api.php/SelectBanking`
- Chức năng: Lấy lịch sử giao dịch ngân hàng

### Telegram Bot
- Nhiều bot tokens cho các kênh khác nhau
- Thông báo: system events, deposits, errors

---

## 12. Environment Variables

```env
# App
APP_URL=https://smmbe.mktproxy.com
APP_LOCALE=vi
APP_TIMEZONE=Asia/Ho_Chi_Minh

# Database
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_DATABASE=smm_server
DB_USERNAME=root
DB_PASSWORD=

# MongoDB
MONGODB_URI=mongodb://127.0.0.1:27017
MONGODB_DATABASE=ssm_logs

# Redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379

# Telegram
TELEGRAM_BOT_TOKEN=xxx
TELEGRAM_CHAT_ID=xxx
TELEGRAM_ERROR_BOT_TOKEN=xxx
TELEGRAM_ERROR_CHAT_ID=xxx
```

---

## 13. Deployment Notes

### Server Requirements
- PHP >= 8.2
- MySQL 8.0+
- MongoDB 4.4+
- Redis 6.0+
- Composer
- Supervisor (cho background jobs)

### Supervisor Config
```ini
[program:order_place]
command=php /var/www/smm_server/artisan order_place
directory=/var/www/smm_server
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/log/order_place.log

[program:activity_log]
command=php /var/www/smm_server/artisan activity_log:save
directory=/var/www/smm_server
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/log/activity_log.log
```

### Cron Job
```bash
* * * * * cd /var/www/smm_server && php artisan schedule:run >> /dev/null 2>&1
```

---

## 14. Bảng tổng hợp

| Component | Technology | Mục đích |
|-----------|-----------|----------|
| Database | MySQL | Dữ liệu chính |
| NoSQL | MongoDB | Activity logs |
| Cache/Queue | Redis | Order queue, transaction codes |
| API Auth | Laravel Sanctum | Token authentication |
| HTTP Client | Guzzle | External API calls |
| Notifications | Telegram Bot | System alerts |
| Localization | Laravel i18n | vi/en support |
