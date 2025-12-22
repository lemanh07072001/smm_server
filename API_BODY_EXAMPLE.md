# API Body Example - Add Order

## Endpoint
```
POST /api/add-order
```

## Headers
```
Authorization: Bearer {your_token}
Content-Type: application/json
Accept: application/json
```

## Request Body Example

Dựa trên log data:

```json
{
  "service_id": 5,
  "provider_service_id": 2,
  "link": "https://www.facebook.com/Theanh28?locale=vi_VN",
  "quantity": 100,
  "reactions": ["like"]
}
```

## Full Order Body (nếu cần tạo order đầy đủ)

```json
{
  "user_id": 1,
  "service_id": 5,
  "provider_service_id": 2,
  "link": "https://www.facebook.com/Theanh28?locale=vi_VN",
  "quantity": 100,
  "status": "pending",
  "cost_rate": 0.50,
  "sell_rate": 1.00,
  "charge_amount": 100.00,
  "cost_amount": 50.00,
  "profit_amount": 50.00,
  "reactions": ["like"]
}
```

## Các trường tùy chọn

```json
{
  "provider_order_id": null,
  "start_count": null,
  "remains": null,
  "refund_amount": 0,
  "final_charge": 0,
  "final_cost": 0,
  "final_profit": 0,
  "is_finalized": false,
  "error_message": null
}
```

## Response Example

```json
{
  "message": "Thành công",
  "data": {
    "provider": {
      "id": 5,
      "name": "...",
      "sell_rate": "1.00",
      ...
    },
    "endpoint": "http://your-domain/api/add-order",
    "method": "POST"
  }
}
```

