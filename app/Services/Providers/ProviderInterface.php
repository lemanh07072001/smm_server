<?php

namespace App\Services\Providers;

use App\Models\Provider;
use App\Models\Service;

interface ProviderInterface
{
    /**
     * Set provider model
     */
    public function setProvider(Provider $provider): self;

    /**
     * Build API URL
     */
    public function buildApiUrl(): string;

    /**
     * Build request body for add order
     */
    public function buildAddOrderBody(Service $service, array $validated): array;

    /**
     * Send request to provider API
     */
    public function sendRequest(Service $service, array $validated): array;

    /**
     * Parse response from provider
     */
    public function parseResponse(array $response): array;

    /**
     * Get provider order ID from response
     */
    public function getOrderIdFromResponse(array $response): ?string;

    /**
     * Check if response is successful
     */
    public function isSuccessResponse(array $response): bool;

    /**
     * Get order status from provider
     */
    public function getOrderStatus(string|array $orderIds): array;

    /**
     * Parse status response from provider
     */
    public function parseStatusResponse(array $response): array;

    /**
     * Map provider status to system status
     */
    public function mapProviderStatus(string $providerStatus): string;
}
