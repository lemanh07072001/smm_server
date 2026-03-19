<?php

namespace App\Services\Providers;

use App\Models\Provider;
use InvalidArgumentException;

class ProviderFactory
{
    protected static array $providers = [
        // Generic SMM Panel format (key/action/service/link/quantity, field "orders")
        // URL lấy từ api_url trong DB — mỗi provider chỉ khác nhau URL
        'trao_doi_tuong_tac' => GenericSmmProvider::class,
        'smm'                => GenericSmmProvider::class,
        'smmking'            => GenericSmmProvider::class,
        'smmapir'            => GenericSmmProvider::class,
        'smmzone'            => GenericSmmProvider::class,

        // Providers có format/logic riêng
        'smm_panel'          => SmmPanelProvider::class,
        'omo'                => OmoProvider::class,
        '1kview'             => OneKViewProvider::class,
        'buffviewer'         => BuffViewerProvider::class,
        // Thêm provider mới ở đây:
        // 'another_provider' => AnotherProvider::class,
    ];

    /**
     * Create provider instance by code.
     * Throws exception nếu code chưa được đăng ký.
     */
    public static function make(Provider $provider): ProviderInterface
    {
        $code = $provider->code;

        if (!isset(self::$providers[$code])) {
            throw new InvalidArgumentException("Provider [{$code}] chưa được đăng ký trong ProviderFactory.");
        }

        return (new self::$providers[$code]())->setProvider($provider);
    }

    public static function isSupported(string $code): bool
    {
        return isset(self::$providers[$code]);
    }

    public static function getSupportedProviders(): array
    {
        return array_keys(self::$providers);
    }

    public static function register(string $code, string $class): void
    {
        self::$providers[$code] = $class;
    }
}
