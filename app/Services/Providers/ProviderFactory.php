<?php

namespace App\Services\Providers;

use App\Models\Provider;

class ProviderFactory
{
    /**
     * Chỉ khai báo các provider có API format KHÁC với TraoDoiTuongTacProvider.
     * Mọi provider không có trong danh sách này sẽ dùng TraoDoiTuongTacProvider làm mặc định.
     */
    protected static array $customProviders = [
        'smm_panel' => SmmPanelProvider::class,
        'omo'       => OmoProvider::class,
        'smm_king'  => SmmKingProvider::class,
        // Thêm provider mới ở đây chỉ khi format API thực sự khác:
        // 'another_provider' => AnotherProvider::class,
    ];

    /**
     * Create provider instance by code.
     * Mặc định dùng TraoDoiTuongTacProvider nếu không có entry riêng.
     */
    public static function make(Provider $provider): ProviderInterface
    {
        $code = $provider->code;
        $providerClass = self::$customProviders[$code] ?? TraoDoiTuongTacProvider::class;

        return (new $providerClass())->setProvider($provider);
    }

    /**
     * Mọi provider đều được hỗ trợ — không có entry riêng thì dùng mặc định.
     */
    public static function isSupported(string $code): bool
    {
        return true;
    }

    /**
     * Get all custom provider codes (không tính mặc định).
     */
    public static function getSupportedProviders(): array
    {
        return array_keys(self::$customProviders);
    }

    /**
     * Register a custom provider dynamically.
     */
    public static function register(string $code, string $class): void
    {
        self::$customProviders[$code] = $class;
    }
}
