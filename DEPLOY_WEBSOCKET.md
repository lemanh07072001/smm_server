# Hướng dẫn Deploy WebSocket (Laravel Reverb) lên Server

## Thông tin
- Backend: https://api.tangfollowvn.com
- Frontend: https://tangfollowvn.com
- Sử dụng Cloudflare

---

## Bước 1: Cấu hình .env trên Server

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.tangfollowvn.com

# Broadcasting
BROADCAST_DRIVER=reverb

# Queue (dùng Redis)
QUEUE_CONNECTION=redis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

# Reverb WebSocket
REVERB_APP_ID=818447
REVERB_APP_KEY=thiusfni4bwwrncesdca
REVERB_APP_SECRET=ln7p3ihycapfpgka0tal
REVERB_HOST=api.tangfollowvn.com
REVERB_PORT=443
REVERB_SCHEME=https

VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST="${REVERB_HOST}"
VITE_REVERB_PORT="${REVERB_PORT}"
VITE_REVERB_SCHEME="${REVERB_SCHEME}"
```

---

## Bước 2: Cấu hình Nginx

Thêm vào file `/etc/nginx/sites-available/api.tangfollowvn.com`:

```nginx
server {
    listen 443 ssl http2;
    server_name api.tangfollowvn.com;

    # SSL Certificate (Cloudflare Origin)
    ssl_certificate /etc/ssl/cloudflare/api.tangfollowvn.com.pem;
    ssl_certificate_key /etc/ssl/cloudflare/api.tangfollowvn.com.key;

    root /var/www/ssmbackend/public;
    index index.php;

    # Laravel API
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    # WebSocket Reverb - QUAN TRỌNG
    location /app {
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_read_timeout 86400;
        proxy_send_timeout 86400;
    }

    location ~ /\.ht {
        deny all;
    }
}
```

Kiểm tra và reload:
```bash
sudo nginx -t
sudo systemctl reload nginx
```

---

## Bước 3: Cấu hình Cloudflare

1. **Vào Cloudflare Dashboard** → Chọn domain `tangfollowvn.com`

2. **Network** → Bật **WebSockets** = ON

3. **SSL/TLS** → Chọn **Full (strict)** hoặc **Full**

4. **DNS** → Đảm bảo `api.tangfollowvn.com` có Proxy ON (orange cloud)

---

## Bước 4: Cấu hình Supervisor

Tạo file `/etc/supervisor/conf.d/ssmbackend.conf`:

```ini
[program:reverb]
process_name=%(program_name)s
command=php /var/www/ssmbackend/artisan reverb:start --host=0.0.0.0 --port=8080
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/www/ssmbackend/storage/logs/reverb.log
stopwaitsecs=3600

[program:queue-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/ssmbackend/artisan queue:work redis --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/www/ssmbackend/storage/logs/worker.log
stopwaitsecs=3600
```

Chạy lệnh:
```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start reverb:*
sudo supervisorctl start queue-worker:*
```

Kiểm tra trạng thái:
```bash
sudo supervisorctl status
```

---

## Bước 5: Clear Cache trên Server

```bash
cd /var/www/ssmbackend
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan config:cache
```

---

## Bước 6: Test WebSocket

### Test từ Browser Console:
```javascript
const ws = new WebSocket('wss://api.tangfollowvn.com/app/thiusfni4bwwrncesdca');

ws.onopen = () => {
    console.log('Connected!');
    ws.send(JSON.stringify({
        event: 'pusher:subscribe',
        data: { channel: 'orders' }
    }));
};

ws.onmessage = (e) => {
    console.log('Message:', JSON.parse(e.data));
};

ws.onerror = (e) => console.log('Error:', e);
```

### Test từ Server (SSH):
```bash
cd /var/www/ssmbackend
php artisan tinker
```

```php
broadcast(new \App\Events\NewOrderCreated(\App\Models\Order::first()));
```

---

## Bước 7: Cấu hình Frontend (Next.js)

### File `.env.local`:
```env
NEXT_PUBLIC_API_URL=https://api.tangfollowvn.com
NEXT_PUBLIC_REVERB_APP_KEY=thiusfni4bwwrncesdca
NEXT_PUBLIC_REVERB_HOST=api.tangfollowvn.com
NEXT_PUBLIC_REVERB_PORT=443
NEXT_PUBLIC_REVERB_SCHEME=https
```

### File `lib/echo.ts`:
```typescript
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

declare global {
  interface Window {
    Pusher: typeof Pusher;
    Echo: Echo;
  }
}

export const initEcho = (token: string) => {
  if (typeof window === 'undefined') return null;

  window.Pusher = Pusher;

  return new Echo({
    broadcaster: 'reverb',
    key: process.env.NEXT_PUBLIC_REVERB_APP_KEY,
    wsHost: process.env.NEXT_PUBLIC_REVERB_HOST,
    wsPort: parseInt(process.env.NEXT_PUBLIC_REVERB_PORT || '443'),
    wssPort: parseInt(process.env.NEXT_PUBLIC_REVERB_PORT || '443'),
    forceTLS: true,
    disableStats: true,
    enabledTransports: ['ws', 'wss'],
    authEndpoint: `${process.env.NEXT_PUBLIC_API_URL}/broadcasting/auth`,
    auth: {
      headers: {
        Authorization: `Bearer ${token}`,
      },
    },
  });
};
```

### Sử dụng trong Component:
```typescript
'use client';

import { useEffect } from 'react';
import { initEcho } from '@/lib/echo';

export function useWebSocket(token: string, userId: number) {
  useEffect(() => {
    if (!token) return;

    const echo = initEcho(token);
    if (!echo) return;

    // Public channel - tất cả orders
    echo.channel('orders')
      .listen('.order.created', (e: any) => {
        console.log('New order:', e);
      });

    // Private channel - thông báo cho user
    echo.private(`user.${userId}`)
      .listen('.order.created', (e: any) => {
        console.log('Your order:', e);
        // Hiển thị toast notification
      })
      .listen('.deposit.success', (e: any) => {
        console.log('Deposit success:', e);
        // Hiển thị toast notification
      });

    return () => {
      echo.leave('orders');
      echo.leave(`private-user.${userId}`);
      echo.disconnect();
    };
  }, [token, userId]);
}
```

---

## Troubleshooting

### 1. WebSocket không kết nối được
- Kiểm tra Cloudflare WebSockets đã bật chưa
- Kiểm tra Nginx config có location `/app` chưa
- Kiểm tra Reverb đang chạy: `sudo supervisorctl status reverb`

### 2. Lỗi 403 Forbidden khi subscribe private channel
- Kiểm tra token có hợp lệ không
- Kiểm tra CORS đã cho phép domain frontend chưa

### 3. Event không broadcast
- Kiểm tra queue worker đang chạy: `sudo supervisorctl status queue-worker`
- Kiểm tra log: `tail -f /var/www/ssmbackend/storage/logs/laravel.log`

### 4. Xem log Reverb
```bash
tail -f /var/www/ssmbackend/storage/logs/reverb.log
```

### 5. Restart tất cả services
```bash
sudo supervisorctl restart all
sudo systemctl reload nginx
```

---

## Tóm tắt URLs

| Service | URL |
|---------|-----|
| API | https://api.tangfollowvn.com |
| WebSocket | wss://api.tangfollowvn.com/app/{key} |
| Auth | https://api.tangfollowvn.com/broadcasting/auth |
| Frontend | https://tangfollowvn.com |

---

## Events có sẵn

1. **NewOrderCreated** - Khi tạo đơn hàng mới
   - Channel: `orders` (public), `private-user.{id}`
   - Event: `.order.created`

2. **DepositSuccess** - Khi nạp tiền thành công
   - Channel: `private-user.{id}`
   - Event: `.deposit.success`
