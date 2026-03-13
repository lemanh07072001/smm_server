# BuffViewer Provider

## Thông tin chung

| Field      | Giá trị              |
|------------|----------------------|
| Code       | `buffviewer`         |
| API URL    | `https://buffviewer.com` |
| Class      | `BuffViewerProvider` |

---

## Đặc điểm

BuffViewer có **URL endpoint thay đổi theo loại dịch vụ**, khác với các provider thông thường chỉ dùng 1 endpoint cố định.

---

## Mapping URL theo dịch vụ

| Group ID                    | Endpoint                                      |
|-----------------------------|-----------------------------------------------|
| `tiktok_like`               | `https://buffviewer.com/api/v2/tiktok-views`  |
| `tiktok_like_livestream`    | `https://buffviewer.com/api/v2/tiktok-views`  |
| `tiktok_follow`             | `https://buffviewer.com/api/v2/tiktok-views`  |
| `tiktok_comment`            | `https://buffviewer.com/api/v2/tiktok-views`  |
| `tiktok_comment_livestream` | `https://buffviewer.com/api/v2/tiktok-views`  |
| `tiktok_share`              | `https://buffviewer.com/api/v2/tiktok-views`  |
| `tiktok_buff_view_live`     | `https://buffviewer.com/api/v2/tiktok-views`  |
| `tiktok_buff_view_video`    | `https://buffviewer.com/api/v2/tiktok-views`  |
| `fb_comment`                | `https://buffviewer.com/api/v2/fb-comment`    |
| `fb_share_content`          | `https://buffviewer.com/api/v2/fb-comment`    |
| *(các group khác)*          | `https://buffviewer.com/api/v2`               |

> **Fallback:** Nếu `group_id` chưa được map nhưng `platform = '2'` (TikTok), tự động dùng endpoint `tiktok-views`.

---

## Request Format (Add Order)

```
POST <endpoint>
Content-Type: application/x-www-form-urlencoded

key      = <api_key>
action   = add
service  = <provider_service_code>
link     = <url>
quantity = <số lượng>
comments = <nội dung comment>   (tùy chọn, chỉ dịch vụ comment)
```

## Response Format (Add Order)

```json
{ "order": 123456 }
```

---

## Request Format (Check Status)

```
POST https://buffviewer.com/api/v2
Content-Type: application/x-www-form-urlencoded

key    = <api_key>
action = status
order  = <order_id hoặc id1,id2,id3>
```

## Response Format (Check Status — single)

```json
{
  "charge": "0.27",
  "start_count": "100",
  "status": "In progress",
  "remains": "900",
  "currency": "USD"
}
```

## Response Format (Check Status — batch)

```json
{
  "123456": { "charge": "0.27", "start_count": "100", "status": "Completed", "remains": "0", "currency": "USD" },
  "123457": { "charge": "0.15", "start_count": "50",  "status": "In progress","remains": "50","currency": "USD" }
}
```

---

## Request Format (Cancel Order)

```
POST https://buffviewer.com/api/v2
Content-Type: application/x-www-form-urlencoded

key    = <api_key>
action = cancel
order  = <order_id hoặc id1,id2,id3>
```

---

## Request Format (Balance)

```
POST https://buffviewer.com/api/v2
Content-Type: application/x-www-form-urlencoded

key    = <api_key>
action = balance
```

## Response Format (Balance)

```json
{ "balance": "123.45", "currency": "USD" }
```

---

## Thêm dịch vụ mới có endpoint riêng

Nếu buffviewer ra thêm endpoint mới (ví dụ `fb-like`), bổ sung vào `$groupEndpointMap` trong [BuffViewerProvider.php](../app/Services/Providers/BuffViewerProvider.php):

```php
protected array $groupEndpointMap = [
    // ... existing entries ...
    'fb_like'  => 'fb-like',   // <-- thêm vào đây
];
```

---

## Thiết lập DB

Thêm record vào bảng `providers`:

| Column    | Giá trị                      |
|-----------|------------------------------|
| `code`    | `buffviewer`                 |
| `name`    | BuffViewer                   |
| `api_url` | `https://buffviewer.com`     |
| `api_key` | *(key từ buffviewer)*        |
