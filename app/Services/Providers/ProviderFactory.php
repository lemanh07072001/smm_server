<?php

namespace App\Services\Providers;

use App\Models\Provider;
use InvalidArgumentException;

class ProviderFactory
{
    /**
     * Map provider code to provider class
     * Khi thêm provider mới, chỉ cần thêm vào đây
     */
    protected static array $providers = [
        'trao_doi_tuong_tac' => TraoDoiTuongTacProvider::class,
        'smm_panel' => SmmPanelProvider::class,
        // Thêm provider mới ở đây:
        // 'another_provider' => AnotherProvider::class,
    ];

    /**
     * Create provider instance by code
     */
    public static function make(Provider $provider): ProviderInterface
    {
        $code = $provider->code;

        if (!isset(self::$providers[$code])) {
            throw new InvalidArgumentException("Provider không được hỗ trợ: {$code}");
        }

        $providerClass = self::$providers[$code];

        return (new $providerClass())->setProvider($provider);
    }

    /**
     * Check if provider is supported
     */
    public static function isSupported(string $code): bool
    {
        return isset(self::$providers[$code]);
    }

    /**
     * Get all supported provider codes
     */
    public static function getSupportedProviders(): array
    {
        return array_keys(self::$providers);
    }

    /**
     * Register a new provider dynamically
     */
    public static function register(string $code, string $class): void
    {
        self::$providers[$code] = $class;
    }
}
