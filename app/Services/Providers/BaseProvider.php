<?php

namespace App\Services\Providers;

use App\Models\Provider;
use App\Models\Service;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

abstract class BaseProvider implements ProviderInterface
{
    protected Provider $provider;

    public function setProvider(Provider $provider): self
    {
        $this->provider = $provider;
        return $this;
    }

    /**
     * Send request to provider API
     */
    public function sendRequest(Service $service, array $validated): array
    {
        $url = $this->buildApiUrl();
        $body = $this->buildAddOrderBody($service, $validated);


        Log::info('Provider API Request', [
            'provider' => $this->provider->code,
            'url' => $url,
            'body' => $body,
        ]);

        try {
            $response = Http::timeout(30)->post($url, $body);

            // Log raw response trước khi parse
            Log::info('Provider API Raw Response', [
                'provider' => $this->provider->code,
                'status_code' => $response->status(),
                'headers' => $response->headers(),
                'raw_body' => $response->body(),
            ]);

            $result = [
                'success' => $response->successful(),
                'status_code' => $response->status(),
                'body' => $response->body(),
                'data' => $response->json() ?? [],
            ];

            // Log error nếu API trả về lỗi
            if (!$response->successful() || isset($result['data']['error'])) {
                Log::error('Provider API Failed', [
                    'provider' => $this->provider->code,
                    'status_code' => $result['status_code'],
                    'error' => $result['data']['error'] ?? $result['body'],
                    'data' => $result['data'],
                ]);
            }

            return $result;
        } catch (\Exception $e) {
            Log::error('Provider API Error', [
                'provider' => $this->provider->code,
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'status_code' => 0,
                'body' => $e->getMessage(),
                'data' => [],
                'exception' => $e,
            ];
        }
    }

    public function parseResponse(array $response): array
    {
        return $response['data'] ?? [];
    }

    public function isSuccessResponse(array $response): bool
    {
        return $response['success'] ?? false;
    }
}
