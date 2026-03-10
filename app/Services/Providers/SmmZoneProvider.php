<?php

namespace App\Services\Providers;

use App\Models\Service;

class SmmZoneProvider extends BaseProvider
{
    /**
     * Build API URL cho SmmZone
     */
    public function buildApiUrl(): string
    {
        return 'https://smmzone.vn/api/v2';
    }

    /**
     * Build request body cho SmmZone
     */
    public function buildAddOrderBody(Service $service, array $validated): array
    {
        return [
            'key' => $this->provider->api_key,
            'action' => 'add',
            'service' => $service->providerService->provider_service_code,
            'link' => $validated['link'],
            'quantity' => $validated['quantity'],
        ];
    }

    /**
     * Lấy order ID từ response
     * SmmZone trả về: {"order": 123456}
     */
    public function getOrderIdFromResponse(array $response): ?string
    {
        $data = $response['data'] ?? [];
        return $data['order'] ?? $data['id'] ?? null;
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
            'orders' => is_array($orderIds) ? implode(',', $orderIds) : $orderIds,
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

    public function parseStatusResponse(array $response): array
    {
        $data = $response['data'] ?? [];
        $result = [];

        if (isset($data['error']) || (isset($data['success']) && $data['success'] === false)) {
            return $result;
        }

        // Single order (flat response)
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
                'currency'          => $data['currency'] ?? 'VND',
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
                    'currency'          => 'VND',
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
                'currency'          => $orderData['currency'] ?? 'VND',
            ];
        }

        return $result;
    }
}
