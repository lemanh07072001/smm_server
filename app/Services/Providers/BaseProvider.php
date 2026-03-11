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


        Log::debug('Provider API Request', [
            'provider' => $this->provider->code,
            'url'      => $url,
            'body'     => $body,
        ]);

        try {
            $response = Http::timeout(30)->asForm()->post($url, $body);

            Log::debug('Provider API Raw Response', [
                'provider'    => $this->provider->code,
                'status_code' => $response->status(),
                'raw_body'    => $response->body(),
            ]);

            $result = [
                'success'       => $response->successful(),
                'status_code'   => $response->status(),
                'body'          => $response->body(),
                'data'          => $response->json() ?? [],
            ];

            // Log error nếu API trả về lỗi
            if (!$response->successful() || isset($result['data']['error'])) {
                Log::error('Provider API Failed', [
                    'provider'      => $this->provider->code,
                    'status_code'   => $result['status_code'],
                    'error'         => $result['data']['error'] ?? $result['body'],
                    'data'          => $result['data'],
                ]);

                return [
                    'success'       => false,
                    'status_code'   => $result['status_code'],
                    'body'          => $result['body'],
                    'data'          => $result['data'],
                    'type'          => 'ERROR_PROVIDER',
                ];

            }

            return $result;
        } catch (\Exception $e) {
            Log::error('Provider API Error', [
                'provider'  => $this->provider->code,
                'url'       => $url,
                'error'     => $e->getMessage(),
            ]);

            return [
                'success'       => false,
                'status_code'   => 0,
                'body'          => $e->getMessage(),
                'data'          => [],
                'exception'     => $e,
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

    /**
     * Get order status from provider
     */
    public function getOrderStatus(string|array $orderIds): array
    {
        $url = $this->buildApiUrl();
        $body = $this->buildStatusBody($orderIds);

        try {
            $response = Http::timeout(10)->asForm()->post($url, $body);

            $result = [
                'success'           => $response->successful(),
                'status_code'       => $response->status(),
                'body'              => $response->body(),
                'data'              => $response->json() ?? [],
                'request_order_id'   => is_array($orderIds) ? null : (string) $orderIds,
                'request_order_ids'  => is_array($orderIds) ? array_values($orderIds) : [$orderIds],
            ];


            return $result;
        } catch (\Exception $e) {
            Log::error('Provider Status Error', [
                'provider'  => $this->provider->code,
                'error'     => $e->getMessage(),
            ]);

            return [
                'success'           => false,
                'status_code'       => 0,
                'body'              => $e->getMessage(),
                'data'              => [],
            ];
        }
    }

    /**
     * Cancel order at provider
     * Mỗi provider có thể override method này để xử lý riêng
     */
    public function canceledOrder(string|array $orderIds): array
    {
        $url = $this->buildCancelUrl();
        $body = $this->buildCancelBody($orderIds);

        try {
            Log::debug('Provider Cancel Order Request', [
                'provider' => $this->provider->code,
                'url'      => $url,
                'body'     => $body,
            ]);

            // Luôn dùng POST method
            $response = Http::timeout(30)->asForm()->post($url, $body);

            $result = [
                'success'       => $response->successful(),
                'status_code'   => $response->status(),
                'body'          => $response->body(),
                'data'          => $response->json() ?? [],
            ];

            // Parse response theo format của từng provider
            $result = $this->parseCancelResponse($result);

            return $result;
        } catch (\Exception $e) {
            Log::error('Provider Cancel Order Error', [
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
     * Build cancel URL - override in child class nếu endpoint khác với buildApiUrl()
     * Mặc định dùng cùng URL với add order
     */
    protected function buildCancelUrl(): string
    {
        return $this->buildApiUrl();
    }

    /**
     * Build request body for cancel order - override in child class
     */
    protected function buildCancelBody(string|array $orderIds): array
    {
        return [];
    }

    /**
     * Parse cancel response - override in child class nếu format khác
     */
    protected function parseCancelResponse(array $response): array
    {
        return $response;
    }

    /**
     * Lấy số dư tài khoản tại provider
     * Mặc định dùng action=balance, override nếu provider có format khác
     */
    public function getBalance(): array
    {
        $url = $this->buildApiUrl();
        $body = [
            'key'    => $this->provider->api_key,
            'action' => 'balance',
        ];

        try {
            $response = Http::timeout(15)->asForm()->post($url, $body);

            return [
                'success'     => $response->successful(),
                'status_code' => $response->status(),
                'body'        => $response->body(),
                'data'        => $response->json() ?? [],
            ];
        } catch (\Exception $e) {
            return [
                'success'     => false,
                'status_code' => 0,
                'body'        => $e->getMessage(),
                'data'        => [],
            ];
        }
    }

    /**
     * Parse balance từ response — override nếu provider trả về format khác
     * Mặc định: {"balance": "123.45", "currency": "USD"}
     * Trả về ['balance' => float, 'currency' => string] hoặc null nếu lỗi
     */
    public function parseBalanceResponse(array $response): ?array
    {
        $data = $response['data'] ?? [];

        if (isset($data['balance'])) {
            return [
                'balance'  => (float) $data['balance'],
                'currency' => $data['currency'] ?? 'USD',
            ];
        }

        return null;
    }

    /**
     * Build request body for status check - override in child class
     */
    protected function buildStatusBody(string|array $orderIds): array
    {
        return [];
    }

    /**
     * Parse status response - override in child class
     */
    public function parseStatusResponse(array $response): array
    {
        return $response['data'] ?? [];
    }

    /**
     * Map provider status to system status - override in child class
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
