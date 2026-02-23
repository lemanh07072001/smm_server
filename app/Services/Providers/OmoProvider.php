<?php

namespace App\Services\Providers;

use App\Models\Service;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * OMO Provider - API format hoàn toàn khác với Trao Đổi Tương Tác
 *
 * API Specification:
 * - Method: POST với JSON body
 * - Request format:
 *   {
 *     "KeyApi": "...",
 *     "Url": "https://...",
 *     "Time": 30,
 *     "Count": "3",
 *     "Type": "VIEW_LIVE_FACEBOOK"
 *   }
 * - Response format: { "IdOrder": "live_fb_xxx" }
 */
class OmoProvider extends BaseProvider
{
    /**
     * Build API URL cho OMO
     * OMO sử dụng endpoint /api/order
     */
    public function buildApiUrl(): string
    {
        $baseUrl = rtrim($this->provider->api_url, '/');
        return $baseUrl . '/api/order';
    }

    /**
     * Build request body cho add order
     * OMO sử dụng format:
     * {
     *   "KeyApi": "...",
     *   "Url": "link",
     *   "Time": 30,
     *   "Count": "quantity",
     *   "Type": "service_code"
     * }
     */
    public function buildAddOrderBody(Service $service, array $validated): array
    {
        $body = [
            'KeyApi' => $this->provider->api_key,
            'Url' => $validated['link'],
            'Count' => (string) $validated['quantity'], // OMO yêu cầu string
            'Type' => $service->providerService->provider_service_code,
        ];

        // Thêm Time nếu có trong validated data (mặc định 30)
        $body['Time'] = $validated['time'] ?? 30;

        return $body;
    }

    /**
     * Override sendRequest để sử dụng JSON body
     * OMO không dùng Bearer token, KeyApi được gửi trong body
     */
    public function sendRequest(Service $service, array $validated): array
    {
        $url = $this->buildApiUrl();
        $body = $this->buildAddOrderBody($service, $validated);

        Log::info('OMO Provider API Request', [
            'provider' => $this->provider->code,
            'url' => $url,
            'body' => $body,
        ]);

        try {
            // OMO yêu cầu JSON body, KeyApi trong body thay vì header
            $response = Http::timeout(30)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                ])
                ->post($url, $body);

            $responseData = $response->json() ?? [];

            Log::info('OMO Provider API Raw Response', [
                'provider' => $this->provider->code,
                'status_code' => $response->status(),
                'raw_body' => $response->body(),
                'parsed_data' => $responseData,
            ]);

            $result = [
                'success' => $response->successful(),
                'status_code' => $response->status(),
                'body' => $response->body(),
                'data' => $responseData,
            ];

            // OMO trả về IdOrder nếu thành công
            // Nếu IdOrder rỗng, kiểm tra xem có error message không
            if (!$this->isSuccessResponse($result)) {
                $errorMsg = $responseData['Error'] ??
                           $responseData['error'] ??
                           $responseData['Message'] ??
                           $responseData['message'] ??
                           'IdOrder is empty';

                Log::error('OMO Provider API Failed', [
                    'provider' => $this->provider->code,
                    'status_code' => $result['status_code'],
                    'error' => $errorMsg,
                    'data' => $result['data'],
                    'request_body' => $body,
                ]);

                return [
                    'success' => false,
                    'status_code' => $result['status_code'],
                    'body' => $result['body'],
                    'data' => array_merge($result['data'], [
                        'error' => $errorMsg
                    ]),
                    'type' => 'ERROR_PROVIDER',
                ];
            }

            return $result;
        } catch (\Exception $e) {
            Log::error('OMO Provider API Error', [
                'provider' => $this->provider->code,
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'status_code' => 0,
                'body' => $e->getMessage(),
                'data' => [],
                'exception' => $e,
            ];
        }
    }

    /**
     * Get order ID từ response của OMO
     * OMO trả về: { "IdOrder": "live_fb_xxx" }
     */
    public function getOrderIdFromResponse(array $response): ?string
    {
        $data = $response['data'] ?? [];
        return $data['IdOrder'] ?? null;
    }

    /**
     * Check success response của OMO
     * OMO coi là success nếu có IdOrder trong response
     */
    public function isSuccessResponse(array $response): bool
    {
        $data = $response['data'] ?? [];
        // Success nếu HTTP 200 và có IdOrder
        return $response['success'] && !empty($data['IdOrder']);
    }

    /**
     * Build URL cho status check
     * OMO dùng endpoint: /api/check_order
     */
    protected function buildStatusUrl(): string
    {
        $baseUrl = rtrim($this->provider->api_url, '/');
        return $baseUrl . '/api/check_order';
    }

    /**
     * Override getOrderStatus để sử dụng endpoint và format riêng của OMO
     */
    public function getOrderStatus(string|array $orderIds): array
    {
        $url = $this->buildStatusUrl();
        $body = $this->buildStatusBody($orderIds);

        try {
            Log::info('OMO Provider Status Request', [
                'provider' => $this->provider->code,
                'url' => $url,
                'body' => $body,
            ]);

            // OMO dùng KeyApi trong body, không dùng Bearer token
            $response = Http::timeout(30)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                ])
                ->post($url, $body);

            $responseData = $response->json() ?? [];

            Log::info('OMO Provider Status Response', [
                'provider' => $this->provider->code,
                'status_code' => $response->status(),
                'data' => $responseData,
            ]);

            $result = [
                'success' => $response->successful(),
                'status_code' => $response->status(),
                'body' => $response->body(),
                'data' => $responseData,
            ];

            return $result;
        } catch (\Exception $e) {
            Log::error('OMO Provider Status Error', [
                'provider' => $this->provider->code,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'status_code' => 0,
                'body' => $e->getMessage(),
                'data' => [],
            ];
        }
    }

    /**
     * Build body cho status check
     * OMO yêu cầu:
     * {
     *   "IdOrders": ["live_fb_xxx", "live_fb_yyy"]
     * }
     *
     * Lưu ý: OMO check status KHÔNG cần KeyApi
     */
    protected function buildStatusBody(string|array $orderIds): array
    {
        // Đảm bảo luôn là array
        $orderIdsArray = is_array($orderIds) ? $orderIds : [$orderIds];

        return [
            'IdOrders' => $orderIdsArray,
        ];
    }

    /**
     * Parse status response từ OMO
     * Response format:
     * [
     *   {
     *     "id": 617807,
     *     "id_order": "live_fb_xxx",
     *     "url": "https://...",
     *     "count": 1,
     *     "count_success": 0,
     *     "count_running": 0,
     *     "status": "FAIL",
     *     "time_order": "2026-02-06 10:04:26",
     *     "time_start": "",
     *     "price_order": 15.0,
     *     "country": "VIEW_LIVE_FACEBOOK_VN",
     *     "refund_percentage": 100
     *   }
     * ]
     */
    public function parseStatusResponse(array $response): array
    {
        $data = $response['data'] ?? [];
        $parsed = [];

        // OMO trả về array các orders
        if (is_array($data)) {
            foreach ($data as $orderInfo) {
                $orderId = $orderInfo['id_order'] ?? null;
                if ($orderId) {
                    $count = $orderInfo['count'] ?? 0;
                    $countSuccess = $orderInfo['count_success'] ?? 0;
                    $countRunning = $orderInfo['count_running'] ?? 0;

                    // Tính remains = count - count_success - count_running
                    $remains = max(0, $count - $countSuccess - $countRunning);

                    $parsed[$orderId] = [
                        'provider_order_id' => $orderId,
                        'status' => $orderInfo['status'] ?? null,
                        'start_count' => $countSuccess, // Số lượng đã hoàn thành
                        'remains' => $remains, // Số lượng còn lại
                        'charge' => $orderInfo['price_order'] ?? 0,
                        'currency' => 'VND',
                    ];
                }
            }
        }

        return $parsed;
    }

    /**
     * Override cancel order cho OMO
     * OMO sử dụng endpoint: /api/cancel
     */
    public function canceledOrder(string|array $orderIds): array
    {
        $url = $this->buildCancelUrl();
        $body = $this->buildCancelBody($orderIds);

        try {
            Log::info('OMO Cancel Order Request', [
                'provider' => $this->provider->code,
                'url' => $url,
                'body' => $body,
            ]);

            // OMO dùng KeyApi trong body, không dùng Bearer token
            $response = Http::timeout(30)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                ])
                ->post($url, $body);

            $responseData = $response->json() ?? [];

            Log::info('OMO Cancel Order Response', [
                'provider' => $this->provider->code,
                'status_code' => $response->status(),
                'data' => $responseData,
            ]);

            $result = [
                'success' => $response->successful(),
                'status_code' => $response->status(),
                'body' => $response->body(),
                'data' => $responseData,
            ];

            $result = $this->parseCancelResponse($result);

            return $result;
        } catch (\Exception $e) {
            Log::error('OMO Cancel Order Error', [
                'provider' => $this->provider->code,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'status_code' => 0,
                'body' => $e->getMessage(),
                'data' => [],
            ];
        }
    }

    /**
     * Build cancel URL
     * OMO dùng endpoint: /api/update_order
     */
    protected function buildCancelUrl(): string
    {
        $baseUrl = rtrim($this->provider->api_url, '/');
        return $baseUrl . '/api/update_order';
    }

    /**
     * Build cancel body
     * OMO yêu cầu:
     * {
     *   "IdOrder": "live_fb_xxx",
     *   "Status": "STOP"
     * }
     */
    protected function buildCancelBody(string|array $orderIds): array
    {
        return [
            'IdOrder' => is_array($orderIds) ? $orderIds[0] : $orderIds,
            'Status' => 'STOP',
        ];
    }

    /**
     * Parse cancel response
     * Response format:
     * {
     *   "Message": "Huỷ đơn hàng thành công"
     * }
     */
    protected function parseCancelResponse(array $response): array
    {
        $data = $response['data'] ?? [];
        $message = $data['Message'] ?? $data['message'] ?? '';

        $isSuccess = $response['success'] && str_contains(strtolower($message), 'thành công');

        $response['success'] = $isSuccess;

        return $response;
    }

    /**
     * Map status từ OMO sang hệ thống
     * OMO sử dụng các trạng thái:
     * - "WAITING", "PENDING", "RUNNING", "SUCCESS", "FAIL", "CANCEL"
     */
    public function mapProviderStatus(string $providerStatus): string
    {
        return match (strtoupper($providerStatus)) {
            'WAITING' => 'pending',
            'PENDING' => 'pending',
            'RUNNING' => 'processing',
            'SUCCESS' => 'completed',
            'FAIL' => 'failed',
            'CANCEL' => 'canceled',
            'PARTIAL' => 'partial',
            default => 'pending', // Default về pending thay vì unknown
        };
    }
}
