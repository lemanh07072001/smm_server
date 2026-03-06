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
            'key' => $this->provider->api_key,
            'action' => 'status',
            'order' => is_array($orderIds) ? implode(',', $orderIds) : $orderIds,
        ];
    }

    protected function buildCancelBody(string|array $orderIds): array
    {
        return [
            'key' => $this->provider->api_key,
            'action' => 'cancel',
            'order' => is_array($orderIds) ? implode(',', $orderIds) : $orderIds,
        ];
    }
}
