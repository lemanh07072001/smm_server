<?php

namespace App\Services\Providers;

use App\Models\Service;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SmmApirProvider extends BaseProvider
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
            'orders' => is_array($orderIds) ? implode(',', $orderIds) : $orderIds,
        ];
    }

    /**
     * Override canceledOrder để xử lý riêng cho SmmApir
     * API yêu cầu: JSON body, field "orders"
     */
    public function canceledOrder(string|array $orderIds): array
    {
        $url = $this->buildCancelUrl();
        $body = $this->buildCancelBody($orderIds);

        try {
            Log::info('SmmApir Cancel Order Request', [
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
            Log::error('SmmApir Cancel Order Error', [
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
     * SmmApir dùng field "orders" (không phải "order")
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
     * Parse status response từ SmmApir
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
        $result = [];

        // Trường hợp lỗi: {"success": false, "error": "..."}
        if (isset($data['error']) || (isset($data['success']) && $data['success'] === false)) {
            return $result;
        }

        // Trường hợp single order (flat): {"charge": "...", "status": "In progress", ...}
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
                'status'      => $data['status'],
                'start_count' => $data['start_count'] ?? 0,
                'remains'     => $data['remains'] ?? 0,
                'charge'      => $data['charge'] ?? 0,
                'currency'    => $data['currency'] ?? 'VND',
            ];
            return $result;
        }

        // Trường hợp batch (nested): {"14204384": {"status": "...", ...}, "14204385": {...}}
        foreach ($data as $orderId => $orderData) {
            if (is_string($orderData)) {
                // Lỗi per-order: value là string error message
                $result[(string) $orderId] = [
                    'provider_order_id' => (string) $orderId,
                    'status'      => 'failed',
                    'error'       => $orderData,
                    'start_count' => 0,
                    'remains'     => 0,
                    'charge'      => 0,
                    'currency'    => 'VND',
                ];
                continue;
            }
            if (!is_array($orderData)) {
                continue;
            }
            $result[(string) $orderId] = [
                'provider_order_id' => (string) $orderId,
                'status'      => $orderData['status'] ?? null,
                'start_count' => $orderData['start_count'] ?? 0,
                'remains'     => $orderData['remains'] ?? 0,
                'charge'      => $orderData['charge'] ?? 0,
                'currency'    => $orderData['currency'] ?? 'VND',
            ];
        }

        return $result;
    }

    /**
     * Map status từ provider sang status của hệ thống
     */
    public function mapProviderStatus(string $providerStatus): string
    {
        return match (strtolower($providerStatus)) {
            'pending'     => 'pending',
            'processing'  => 'processing',
            'in progress' => 'in_progress',
            'completed'   => 'completed',
            'canceled'    => 'canceled',
            'partial'     => 'partial',
            'refunded'    => 'refunded',
            'failed'      => 'failed',
            default       => 'unknown',
        };
    }
}
