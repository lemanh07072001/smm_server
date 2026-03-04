<?php

namespace App\Services\Providers;

use App\Models\Service;

class SmmKingProvider extends BaseProvider
{
    public function buildApiUrl(): string
    {
        return rtrim($this->provider->api_url, '/') . '/api/v2';
    }

    public function buildAddOrderBody(Service $service, array $validated): array
    {
        return [
            'key'      => $this->provider->api_key,
            'action'   => 'add',
            'service'  => $service->providerService->provider_service_code,
            'link'     => $validated['link'],
            'quantity' => $validated['quantity'],
        ];
    }

    /**
     * Add order response: {"order": 12563}
     */
    public function getOrderIdFromResponse(array $response): ?string
    {
        $data = $response['data'] ?? [];
        return isset($data['order']) ? (string) $data['order'] : null;
    }

    public function isSuccessResponse(array $response): bool
    {
        if (!($response['success'] ?? false)) {
            return false;
        }

        $data = $response['data'] ?? [];

        if (isset($data['error'])) {
            return false;
        }

        return isset($data['order']);
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
     * Status response (single):
     * {"charge": "0.5689", "start_count": "1525", "status": "Partial", "remains": "569", "currency": "USD"}
     *
     * Status response (batch):
     * {"12563": {"charge": "0.5689", "start_count": "1525", "status": "Partial", "remains": "569", "currency": "USD"}, ...}
     */
    public function parseStatusResponse(array $response): array
    {
        $data = $response['data'] ?? [];
        $result = [];

        if (empty($data)) {
            return $result;
        }

        // Lỗi: {"error": "..."}
        if (isset($data['error'])) {
            return $result;
        }

        // Single order (flat): có key "status" trực tiếp là string
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
                'start_count'       => (int) ($data['start_count'] ?? 0),
                'remains'           => (int) ($data['remains'] ?? 0),
                'charge'            => (float) ($data['charge'] ?? 0),
                'currency'          => $data['currency'] ?? 'USD',
            ];

            return $result;
        }

        // Batch (nested by order ID)
        foreach ($data as $orderId => $orderData) {
            if (!is_array($orderData)) {
                continue;
            }

            // Lỗi per-order: {"362": {"error": "Incorrect order ID"}}
            if (isset($orderData['error'])) {
                $result[(string) $orderId] = [
                    'provider_order_id' => (string) $orderId,
                    'status'            => 'failed',
                    'error'             => $orderData['error'],
                    'start_count'       => 0,
                    'remains'           => 0,
                    'charge'            => 0,
                    'currency'          => 'USD',
                ];
                continue;
            }

            $result[(string) $orderId] = [
                'provider_order_id' => (string) $orderId,
                'status'            => $orderData['status'] ?? null,
                'start_count'       => (int) ($orderData['start_count'] ?? 0),
                'remains'           => (int) ($orderData['remains'] ?? 0),
                'charge'            => (float) ($orderData['charge'] ?? 0),
                'currency'          => $orderData['currency'] ?? 'USD',
            ];
        }

        return $result;
    }

    public function mapProviderStatus(string $providerStatus): string
    {
        return match (strtolower($providerStatus)) {
            'pending'     => 'pending',
            'processing'  => 'processing',
            'in progress' => 'in_progress',
            'active'      => 'in_progress',
            'completed'   => 'completed',
            'canceled'    => 'canceled',
            'partial'     => 'partial',
            'refunded'    => 'refunded',
            'failed'      => 'failed',
            default       => 'unknown',
        };
    }
}
