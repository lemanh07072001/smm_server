<?php

namespace App\Services\Providers;

use App\Models\Provider;
use InvalidArgumentException;

class ProviderFactory
{
    protected static array $providers = [
        'trao_doi_tuong_tac' => TraoDoiTuongTacProvider::class,
        'smm_panel'          => SmmPanelProvider::class,
        'omo'                => OmoProvider::class,
        'smm_king'           => SmmKingProvider::class,
        'smm'                => SmmProvider::class,
        '1kview'             => OneKViewProvider::class,
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
