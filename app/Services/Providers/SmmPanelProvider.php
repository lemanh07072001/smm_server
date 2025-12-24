<?php

namespace App\Services\Providers;

use App\Models\Service;

class SmmPanelProvider extends BaseProvider
{
    /**
     * Build API URL cho SMM Panel
     */
    public function buildApiUrl(): string
    {
        // SMM Panel thường dùng endpoint /api/v2
        return rtrim($this->provider->api_url, '/') . '/api/v2';
    }

    /**
     * Build request body cho SMM Panel
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
     * SMM Panel trả về: {"order": 123456}
     */
    public function getOrderIdFromResponse(array $response): ?string
    {
        $data = $response['data'] ?? [];
        return $data['order'] ?? $data['id'] ?? null;
    }

    /**
     * Kiểm tra response thành công
     * SMM Panel trả về error nếu có lỗi
     */
    public function isSuccessResponse(array $response): bool
    {
        if (!($response['success'] ?? false)) {
            return false;
        }

        $data = $response['data'] ?? [];

        // Nếu có error field thì thất bại
        if (isset($data['error'])) {
            return false;
        }

        // Nếu có order ID thì thành công
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
}
