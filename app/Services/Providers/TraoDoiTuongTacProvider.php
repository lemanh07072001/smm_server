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
        return [
            'key' => $this->provider->api_key,
            'action' => 'add',
            'service' => $service->providerService->provider_service_code,
            'link' => $validated['link'],
            'quantity' => $validated['quantity'],
        ];
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
}
