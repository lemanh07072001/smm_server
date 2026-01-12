# SSM Backend - Hệ thống SMM (Social Media Marketing)

## 📋 Tổng quan

SSM Backend là một ứng dụng Laravel 10 phục vụ hệ thống SMM (Social Media Marketing). Hệ thống cho phép người dùng đặt các dịch vụ tăng tương tác mạng xã hội (like, follow, comment, view...) thông qua các nhà cung cấp bên thứ ba (providers).

## 🏗️ Kiến trúc hệ thống

### Tech Stack
- **Framework:** Laravel 10
- **Database:** MySQL (dữ liệu chính), MongoDB (activity logs)
- **Cache/Queue:** Redis
- **Authentication:** Laravel Sanctum
- **HTTP Client:** Guzzle
- **Notifications:** Telegram Bot

### Luồng xử lý chính

1. **Đặt đơn hàng:** User → API → Validate → Trừ tiền → Tạo Order → Đẩy vào Redis Queue
2. **Xử lý đơn:** Worker đọc từ Redis → Gọi Provider API → Cập nhật status
3. **Theo dõi:** Background job check status định kỳ từ Provider

### Cấu trúc thư mục quan trọng

```
app/
├── Console/Commands/      # Artisan commands (PlaceOrder, CheckOrderStatus...)
├── Helpers/               # Helper classes (RedisHelper, OrderActivityLogger...)
├── Http/Controllers/      # API Controllers
├── Models/                # Eloquent Models (User, Order, Service, Provider...)
└── Services/Providers/    # Provider implementations (SmmPanel, TraoDoiTuongTac)
```

## 🔑 Models chính

- **User:** Quản lý người dùng, balance, discount
- **Order:** Đơn hàng với status (pending, processing, completed, failed...)
- **Service:** Dịch vụ bán cho user (Facebook likes, Tiktok views...)
- **Provider:** Nhà cung cấp bên thứ ba
- **ProviderService:** Mapping service với provider service

## 📚 Tài liệu chi tiết

Xem file **[PROJECT_DOCUMENTATION.md](./PROJECT_DOCUMENTATION.md)** để biết thêm chi tiết về:
- Models & Relationships
- API Endpoints
- Helper Classes
- Provider Architecture
- Database Configuration
- Deployment Notes

## 🚀 Quick Start

### Yêu cầu hệ thống
- PHP >= 8.2
- MySQL 8.0+
- MongoDB 4.4+
- Redis 6.0+
- Composer

### Cài đặt

```bash
# Install dependencies
composer install

# Setup environment
cp .env.example .env
php artisan key:generate

# Run migrations
php artisan migrate

# Seed dữ liệu (nếu cần)
php artisan db:seed
```

### Chạy Development

```bash
# Chạy server + queue + vite cùng lúc
composer dev

# Hoặc chạy riêng lẻ:
php artisan serve          # Laravel server
php artisan queue:work     # Queue worker
npm run dev                # Vite dev server
```

### Background Workers (Production)

Sử dụng Supervisor để chạy các workers:

```bash
# Xử lý đơn hàng từ Redis queue
php artisan order_place

# Lưu activity logs vào MongoDB
php artisan activity_log:save

# Check status đơn hàng định kỳ
php artisan schedule:run   # (chạy qua cron)
```

## 🔧 Artisan Commands

| Command | Mô tả |
|---------|-------|
| `order_place` | Xử lý đơn hàng pending từ Redis queue, đẩy lên provider |
| `activity_log:save` | Lưu activity logs từ Redis queue vào MongoDB |
| `check:order-status` | Kiểm tra và cập nhật status đơn hàng từ provider |
| `check:bank` | Kiểm tra giao dịch ngân hàng tự động |
| `generate:order-report` | Tạo báo cáo đơn hàng |

## 🌐 API Endpoints chính

### Public (Không cần auth)
- `POST /api/register` - Đăng ký
- `POST /api/login` - Đăng nhập
- `GET /api/categories/all` - Danh sách categories
- `GET /api/services/all` - Danh sách services

### Protected (Cần auth:sanctum)
- `POST /api/add-order` - Tạo đơn hàng mới
- `GET /api/orders` - Danh sách đơn hàng
- `GET /api/dashboard` - Thống kê dashboard
- `GET /api/transactions` - Lịch sử giao dịch
- `POST /api/code-transactions` - Nạp tiền bằng mã giao dịch

Xem đầy đủ tại [PROJECT_DOCUMENTATION.md](./PROJECT_DOCUMENTATION.md#3-api-endpoints)

## ⚙️ Environment Variables

Các biến môi trường quan trọng trong `.env`:

```env
# Database
DB_CONNECTION=mysql
DB_DATABASE=ssm_backend

# MongoDB (Activity Logs)
MONGODB_DATABASE=ssm_logs

# Redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379

# Telegram Notifications
TELEGRAM_BOT_TOKEN=xxx
TELEGRAM_CHAT_ID=xxx
TELEGRAM_ERROR_BOT_TOKEN=xxx
TELEGRAM_ERROR_CHAT_ID=xxx
```

## 📦 Dependencies chính

- `laravel/framework ^10.10` - Laravel framework
- `laravel/sanctum ^3.3` - API authentication
- `guzzlehttp/guzzle ^7.2` - HTTP client cho provider APIs
- `mongodb/laravel-mongodb ^5.5` - MongoDB driver
- `laravel/reverb ^1.7` - WebSocket server

## 📝 Notes

- Hệ thống sử dụng Redis queue để xử lý đơn hàng bất đồng bộ
- Activity logs được lưu vào MongoDB để tối ưu hiệu suất
- Có tích hợp Telegram để gửi thông báo lỗi hệ thống
- Order status: `pending` → `processing` → `in_progress` → `completed`
- Hỗ trợ nhiều providers: SmmPanel, TraoDoiTuongTac
