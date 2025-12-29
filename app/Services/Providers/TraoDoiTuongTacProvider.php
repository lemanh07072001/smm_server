<?php

namespace App\Services\Providers;

use App\Models\Service;

class TraoDoiTuongTacProvider extends BaseProvider
{
    public function buildApiUrl(): string
    {
        $baseUrl = rtrim($this->provider->api_url, '/');
        return $baseUrl . '/api/v3';
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

        // Thêm comments nếu có
        if (!empty($validated['comments'])) {
            $body['comments'] = $validated['comments'];
        }

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
            'key' => $this->provider->api_key,
            'action' => 'status',
            'order' => is_array($orderIds) ? implode(',', $orderIds) : $orderIds,
        ];
    }

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
        $result = [];

        foreach ($data as $orderId => $orderData) {
            $result[$orderId] = [
                'provider_order_id' => $orderId,
                'status' => $orderData['status'] ?? null,
                'start_count' => $orderData['start_count'] ?? 0,
                'remains' => $orderData['remains'] ?? 0,
                'charge' => $orderData['charge'] ?? 0,
                'currency' => $orderData['currency'] ?? 'VND',
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
            'pending' => 'pending',
            'in progress', 'inprogress', 'processing' => 'processing',
            'completed', 'complete' => 'completed',
            'canceled', 'cancelled', 'refunded' => 'canceled',
            'partial' => 'partial',
            default => 'unknown',
        };
    }
}
