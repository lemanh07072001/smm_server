<?php

namespace App\Services\Providers;

use App\Models\Service;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TraoDoiTuongTacProvider extends BaseProvider
{
    public function buildApiUrl(): string
    {
        return $this->provider->api_url;
    }

    public function buildAddOrderBody(Service $service, array $validated): array
    {
        $body = [
            'key' => $this->provider->api_key,
            'action' => 'add',
            'service' => $service->providerService->provider_service_code,
            'link' => $validated['link'],
            'quantity' => $validated['quantity'],
        ];

        // Thêm comments nếu có - convert literal \n thành newline thực cho API
        if (!empty($validated['comments'])) {
            $body['comments'] = str_replace('\n', "\n", $validated['comments']);
        }

        logger(json_encode($body, JSON_UNESCAPED_UNICODE));
        return $body;
    }

    public function getOrderIdFromResponse(array $response): ?string
    {
        $data = $response['data'] ?? [];
        return $data['order'] ?? $data['id'] ?? null;
    }

    public function isSuccessResponse(array $response): bool
    {
        return ($response['success'] ?? false) && !isset($response['data']['error']);
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
     * Override canceledOrder để xử lý riêng cho Trao Đổi Tương Tác
     * API yêu cầu: JSON body, field "orders"
     */
    public function canceledOrder(string|array $orderIds): array
    {
        $url = $this->buildCancelUrl();
        $body = $this->buildCancelBody($orderIds);

        try {
            Log::info('TraoDoiTuongTac Cancel Order Request', [
                'provider'  => $this->provider->code,
                'url'       => $url,
                'body'      => $body,
            ]);

            // Gọi API với JSON body
            $response = Http::timeout(30)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                ])
                ->post($url, $body);

            $result = [
                'success'       => $response->successful(),
                'status_code'   => $response->status(),
                'body'          => $response->body(),
                'data'          => $response->json() ?? [],
            ];

            // Parse response
            $result = $this->parseCancelResponse($result);

            return $result;
        } catch (\Exception $e) {
            Log::error('TraoDoiTuongTac Cancel Order Error', [
                'provider'  => $this->provider->code,
                'error'     => $e->getMessage(),
            ]);

            return [
                'success'       => false,
                'status_code'   => 0,
                'body'          => $e->getMessage(),
                'data'          => [],
            ];
        }
    }

    /**
     * Build request body cho cancel order
     * Trao Đổi Tương Tác dùng field "orders" (không phải "order")
     */
    protected function buildCancelBody(string|array $orderIds): array
    {
        return [
            'key' => $this->provider->api_key,
            'action' => 'cancel',
            'orders' => is_array($orderIds) ? implode(',', $orderIds) : $orderIds,
        ];
    }

    /**
     * Nếu provider có endpoint riêng cho cancel, override buildCancelUrl():
     * 
     * protected function buildCancelUrl(): string
     * {
     *     return rtrim($this->provider->api_url, '/') . '/api/v3/cancel';
     * }
     * 
     * Nếu response format khác, override parseCancelResponse():
     * 
     * protected function parseCancelResponse(array $response): array
     * {
     *     // Custom parsing logic
     *     return $response;
     * }
     */

    /**
     * Parse status response từ Trao Đổi Tương Tác
     * Response format:
     * {
     *     "13473430": {
     *         "charge": 1,
     *         "start_count": -1,
     *         "status": "Canceled",
     *         "remains": 1,
     *         "currency": "VND"
     *     }
     * }
     */
    public function parseStatusResponse(array $response): array
    {
        $data = $response['data'] ?? [];

        Log::info('TraoDoiTuongTac parseStatusResponse', [
            'raw_data'   => $data,
            'data_keys'  => array_keys((array) $data),
            'data_count' => count((array) $data),
        ]);

        $result = [];

        foreach ($data as $orderId => $orderData) {
            $result[$orderId] = [
                'provider_order_id' => $orderId,
                'status'      => $orderData['status'] ?? null,
                'start_count' => $orderData['start_count'] ?? 0,
                'remains'     => $orderData['remains'] ?? 0,
                'charge'      => $orderData['charge'] ?? 0,
                'currency'    => $orderData['currency'] ?? 'VND',
            ];
        }

        Log::info('TraoDoiTuongTac parseStatusResponse result', [
            'result_keys' => array_keys($result),
        ]);

        return $result;
    }

    /**
     * Map status từ provider sang status của hệ thống
     */
    public function mapProviderStatus(string $providerStatus): string
    {
          return match (strtolower($providerStatus)) {

            'pending'                                   => 'pending',
            'processing'                                => 'processing',
            'in progress'                               => 'in_progress',
            'completed'                                 => 'completed',
            'canceled'                                  => 'canceled',
            'partial'                                   => 'partial',
            'refunded'                                  => 'refunded',
            'failed'                                    => 'failed',
            default                                     => 'unknown',
        };

    }
}
