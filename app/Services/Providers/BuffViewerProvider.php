<?php

namespace App\Services\Providers;

use App\Models\Order;
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

        // Response của cancel là mảng: [{"order": 123, "cancel": 1}, ...]
        // Lỗi từng order: {"order": "456", "cancel": {"error": "Incorrect order ID"}}
        // Chỉ thành công khi mọi order đều có cancel truthy và không phải error
        if (!empty($response['is_cancel'])) {
            if (empty($data)) {
                return false;
            }

            foreach ($data as $item) {
                if (!is_array($item)) {
                    return false;
                }

                $cancel = $item['cancel'] ?? null;

                if (is_array($cancel) || empty($cancel)) {
                    return false;
                }
            }

            return true;
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

    /**
     * Override getOrderStatus: BuffViewer dùng endpoint khác nhau theo loại service.
     * Query DB để tìm endpoint của từng order → gọi từng endpoint → merge kết quả.
     */
    public function getOrderStatus(string|array $orderIds): array
    {
        $ids = is_array($orderIds) ? $orderIds : [$orderIds];

        // Tra DB: lấy group_id của service cho từng provider_order_id
        $orders = Order::whereIn('provider_order_id', $ids)
            ->with('service:id,group_id')
            ->get(['id', 'provider_order_id', 'service_id']);

        // Group order_id theo endpoint suffix
        $endpointGroups = [];
        foreach ($orders as $order) {
            $groupId = $order->service->group_id ?? '';
            $suffix  = $this->groupEndpointMap[$groupId] ?? null;

            // Fallback: nếu chưa map, dùng tiktok-views (default)
            $suffix = $suffix ?? 'tiktok-views';

            $endpointGroups[$suffix][] = $order->provider_order_id;
        }

        // Nếu không tìm được order nào trong DB, fallback gọi endpoint đầu tiên trong map
        if (empty($endpointGroups)) {
            $defaultSuffix = array_values($this->groupEndpointMap)[0] ?? 'tiktok-views';
            $endpointGroups[$defaultSuffix] = $ids;
        }

        // Gọi API từng endpoint và merge kết quả
        $mergedData = [];
        $lastSuccess = false;
        $lastStatusCode = 0;

        foreach ($endpointGroups as $suffix => $groupOrderIds) {
            $url  = $this->getBaseUrl() . '/api/v2/' . $suffix;
            $body = $this->buildStatusBody($groupOrderIds);

            Log::debug('BuffViewer getOrderStatus', [
                'url'       => $url,
                'order_ids' => $groupOrderIds,
            ]);

            try {
                $response = Http::timeout(10)->asForm()->post($url, $body);
                $lastSuccess    = $response->successful();
                $lastStatusCode = $response->status();
                $data = $response->json() ?? [];

                // Nếu là single order response (có key 'status' ở root)
                if (count($groupOrderIds) === 1 && isset($data['status'])) {
                    $mergedData[$groupOrderIds[0]] = $data;
                } else {
                    // Batch response: {"orderId": {...}, ...}
                    foreach ($data as $oid => $odata) {
                        $mergedData[$oid] = $odata;
                    }
                }
            } catch (\Exception $e) {
                Log::error('BuffViewer getOrderStatus Error', [
                    'url'   => $url,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [
            'success'            => $lastSuccess,
            'status_code'        => $lastStatusCode,
            'body'               => json_encode($mergedData),
            'data'               => $mergedData,
            'request_order_id'   => is_array($orderIds) ? null : (string) $orderIds,
            'request_order_ids'  => $ids,
        ];
    }

    protected function buildCancelBody(string|array $orderIds): array
    {
        return [
            'key'    => $this->provider->api_key,
            'action' => 'cancel',
            'orders' => is_array($orderIds) ? implode(',', $orderIds) : $orderIds,
        ];
    }

    /**
     * Override canceledOrder: BuffViewer dùng endpoint khác nhau theo loại service,
     * giống getOrderStatus. Query DB để tìm endpoint của từng order.
     *
     * Response format: [{"order": 123, "cancel": 1}, {"order": "456", "cancel": {"error": "..."}}]
     */
    public function canceledOrder(string|array $orderIds): array
    {
        $ids = is_array($orderIds) ? $orderIds : [$orderIds];

        $endpointGroups = $this->groupOrderIdsByEndpoint($ids);

        $mergedData     = [];
        $allSuccess     = true;
        $lastStatusCode = 0;

        foreach ($endpointGroups as $suffix => $groupOrderIds) {
            $url  = $this->getBaseUrl() . '/api/v2/' . $suffix;
            $body = $this->buildCancelBody($groupOrderIds);

            Log::debug('BuffViewer canceledOrder', [
                'provider'  => $this->provider->code,
                'url'       => $url,
                'order_ids' => $groupOrderIds,
            ]);

            try {
                $response       = Http::timeout(30)->asForm()->post($url, $body);
                $lastStatusCode = $response->status();
                $data           = $response->json() ?? [];

                if (!$response->successful()) {
                    $allSuccess = false;
                }

                Log::debug('BuffViewer canceledOrder Response', [
                    'provider'    => $this->provider->code,
                    'status_code' => $lastStatusCode,
                    'raw_body'    => $response->body(),
                ]);

                // Provider trả lỗi chung cho cả request: {"error": "..."}
                if (isset($data['error'])) {
                    $allSuccess = false;

                    foreach ($groupOrderIds as $oid) {
                        $mergedData[] = ['order' => $oid, 'cancel' => ['error' => $data['error']]];
                    }

                    continue;
                }

                foreach ($data as $item) {
                    if (is_array($item) && isset($item['order'])) {
                        $mergedData[] = $item;
                    }
                }
            } catch (\Exception $e) {
                $allSuccess = false;

                Log::error('BuffViewer canceledOrder Error', [
                    'provider' => $this->provider->code,
                    'url'      => $url,
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        return [
            'success'     => $allSuccess,
            'status_code' => $lastStatusCode,
            'body'        => json_encode($mergedData),
            'data'        => $mergedData,
            'is_cancel'   => true,
        ];
    }

    /**
     * Nhóm provider_order_id theo endpoint suffix dựa vào group_id của service
     */
    protected function groupOrderIdsByEndpoint(array $ids): array
    {
        $orders = Order::whereIn('provider_order_id', $ids)
            ->with('service:id,group_id')
            ->get(['id', 'provider_order_id', 'service_id']);

        $suffixByOrderId = [];
        foreach ($orders as $order) {
            $groupId = $order->service->group_id ?? '';

            $suffixByOrderId[(string) $order->provider_order_id] =
                $this->groupEndpointMap[$groupId] ?? 'tiktok-views';
        }

        // ID không tìm thấy trong DB vẫn phải được gửi đi, dùng endpoint mặc định
        $endpointGroups = [];
        foreach ($ids as $id) {
            $suffix = $suffixByOrderId[(string) $id] ?? 'tiktok-views';

            $endpointGroups[$suffix][] = $id;
        }

        return $endpointGroups;
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
