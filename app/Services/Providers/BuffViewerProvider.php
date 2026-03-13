<?php

namespace App\Services\Providers;

use App\Models\Service;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * BuffViewer Provider
 *
 * URL thay đổi theo platform của service:
 * - TikTok (platform = '2'): https://buffviewer.com/api/v2/tiktok-views
 * - Facebook comment (group_id = 'fb_comment'): https://buffviewer.com/api/v2/fb-comment
 * - Các dịch vụ khác: https://buffviewer.com/api/v2
 *
 * Request/Response format giống SmmPanel standard.
 */
class BuffViewerProvider extends BaseProvider
{
    /**
     * Map từ group_id sang endpoint suffix
     */
    protected array $groupEndpointMap = [
        // 'tiktok_like'                   => 'tiktok-views',
        // 'tiktok_like_livestream'        => 'tiktok-views',
        // 'tiktok_follow'                 => 'tiktok-views',
        // 'tiktok_comment'                => 'tiktok-views',
        // 'tiktok_comment_livestream'     => 'tiktok-views',
        // 'tiktok_share'                  => 'tiktok-views',
        // 'tiktok_buff_view_live'         => 'tiktok-views',
        'tiktok_buff_view_video'        => 'tiktok-views',
        'fb_comment'                    => 'fb-comment',
        // 'fb_share_content'              => 'fb-comment',
    ];

    /**
     * Build API URL mặc định (dùng cho getOrderStatus, canceledOrder, getBalance)
     * Các dịch vụ không thuộc group nào sẽ dùng endpoint này
     */
    /**
     * Lấy base URL gốc (bỏ /api/v2 nếu đã có trong api_url)
     */
    protected function getBaseUrl(): string
    {
        $url = rtrim($this->provider->api_url, '/');
        // Strip /api/v2 nếu api_url trong DB đã chứa sẵn
        return rtrim(preg_replace('#/api/v2$#', '', $url), '/');
    }

    public function buildApiUrl(): string
    {
        return $this->getBaseUrl() . '/api/v2';
    }

    /**
     * Build API URL dựa vào service (dùng khi tạo order)
     */
    protected function buildApiUrlForService(Service $service): string
    {
        $base = $this->getBaseUrl() . '/api/v2';
        $groupId = $service->group_id ?? '';
        $suffix = $this->groupEndpointMap[$groupId] ?? null;

        // Fallback theo platform nếu group chưa được map
        if ($suffix === null && $service->platform === '2') {
            $suffix = 'tiktok-views';
        }

        return $suffix ? $base . '/' . $suffix : $base;
    }

    /**
     * Build request body cho add order
     */
    public function buildAddOrderBody(Service $service, array $validated): array
    {
        $body = [
            'key'      => $this->provider->api_key,
            'action'   => 'add',
            'service'  => $service->providerService->provider_service_code,
            'link'     => $validated['link'],
            'quantity' => $validated['quantity'],
        ];

        if (!empty($validated['comments'])) {
            $body['comments'] = str_replace('\n', "\n", $validated['comments']);
        }

        return $body;
    }

    /**
     * Override sendRequest để dùng URL theo service
     */
    public function sendRequest(Service $service, array $validated): array
    {
        $url  = $this->buildApiUrlForService($service);
        $body = $this->buildAddOrderBody($service, $validated);

        Log::debug('BuffViewer Provider API Request', [
            'provider' => $this->provider->code,
            'url'      => $url,
            'body'     => $body,
        ]);

        try {
            $response = Http::timeout(30)->asForm()->post($url, $body);

            Log::debug('BuffViewer Provider API Raw Response', [
                'provider'    => $this->provider->code,
                'status_code' => $response->status(),
                'raw_body'    => $response->body(),
            ]);

            $result = [
                'success'     => $response->successful(),
                'status_code' => $response->status(),
                'body'        => $response->body(),
                'data'        => $response->json() ?? [],
            ];

            if (!$response->successful() || isset($result['data']['error'])) {
                Log::error('BuffViewer Provider API Failed', [
                    'provider'    => $this->provider->code,
                    'status_code' => $result['status_code'],
                    'error'       => $result['data']['error'] ?? $result['body'],
                ]);

                return [
                    'success'     => false,
                    'status_code' => $result['status_code'],
                    'body'        => $result['body'],
                    'data'        => $result['data'],
                    'type'        => 'ERROR_PROVIDER',
                ];
            }

            return $result;
        } catch (\Exception $e) {
            Log::error('BuffViewer Provider API Error', [
                'provider' => $this->provider->code,
                'url'      => $url,
                'error'    => $e->getMessage(),
            ]);

            return [
                'success'     => false,
                'status_code' => 0,
                'body'        => $e->getMessage(),
                'data'        => [],
                'exception'   => $e,
            ];
        }
    }

    /**
     * Lấy order ID từ response
     * BuffViewer trả về: {"order": 123456}
     */
    public function getOrderIdFromResponse(array $response): ?string
    {
        $data = $response['data'] ?? [];
        return isset($data['order']) ? (string) $data['order'] : ($data['id'] ?? null);
    }

    /**
     * Kiểm tra response thành công
     */
    public function isSuccessResponse(array $response): bool
    {
        if (!($response['success'] ?? false)) {
            return false;
        }

        $data = $response['data'] ?? [];

        if (isset($data['error'])) {
            return false;
        }

        return isset($data['order']) || isset($data['id']);
    }

    protected function buildStatusBody(string|array $orderIds): array
    {
        return [
            'key'    => $this->provider->api_key,
            'action' => 'status',
            'order'  => is_array($orderIds) ? implode(',', $orderIds) : $orderIds,
        ];
    }

    protected function buildCancelBody(string|array $orderIds): array
    {
        return [
            'key'    => $this->provider->api_key,
            'action' => 'cancel',
            'order'  => is_array($orderIds) ? implode(',', $orderIds) : $orderIds,
        ];
    }

    /**
     * Parse status response
     * Response format: {"charge": "...", "start_count": "...", "status": "...", "remains": "..."}
     * hoặc batch: {"123": {"status": "...", ...}, "456": {...}}
     */
    public function parseStatusResponse(array $response): array
    {
        $data   = $response['data'] ?? [];
        $result = [];

        if (isset($data['error']) || (isset($data['success']) && $data['success'] === false)) {
            return $result;
        }

        // Single order (flat)
        if (isset($data['status']) && !is_array($data['status'])) {
            $orderId = $response['request_order_id'] ?? null;

            if ($orderId === null && isset($response['request_order_ids']) && count($response['request_order_ids']) === 1) {
                $orderId = (string) $response['request_order_ids'][0];
            }

            if ($orderId === null) {
                return $result;
            }

            $result[$orderId] = [
                'provider_order_id' => $orderId,
                'status'            => $data['status'],
                'start_count'       => $data['start_count'] ?? 0,
                'remains'           => $data['remains'] ?? 0,
                'charge'            => $data['charge'] ?? 0,
                'currency'          => $data['currency'] ?? 'USD',
            ];

            return $result;
        }

        // Batch (nested by order ID)
        foreach ($data as $orderId => $orderData) {
            if (is_string($orderData)) {
                $result[(string) $orderId] = [
                    'provider_order_id' => (string) $orderId,
                    'status'            => 'failed',
                    'error'             => $orderData,
                    'start_count'       => 0,
                    'remains'           => 0,
                    'charge'            => 0,
                    'currency'          => 'USD',
                ];
                continue;
            }

            if (!is_array($orderData)) {
                continue;
            }

            $result[(string) $orderId] = [
                'provider_order_id' => (string) $orderId,
                'status'            => $orderData['status'] ?? null,
                'start_count'       => $orderData['start_count'] ?? 0,
                'remains'           => $orderData['remains'] ?? 0,
                'charge'            => $orderData['charge'] ?? 0,
                'currency'          => $orderData['currency'] ?? 'USD',
            ];
        }

        return $result;
    }
}
