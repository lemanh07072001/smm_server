<?php

namespace App\Services\Providers;

use App\Models\Provider;

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
        'gianglike'          => GenericSmmProvider::class,

        // Providers có format/logic riêng (chỉ cần khai báo provider KHÁC format chuẩn ở đây)
        'smm_panel'          => SmmPanelProvider::class,
        'omo'                => OmoProvider::class,
        '1kview'             => OneKViewProvider::class,
        'buffviewer'         => BuffViewerProvider::class,
        // Provider dùng format chuẩn SMM panel KHÔNG cần khai báo —
        // mặc định đã fallback về GenericSmmProvider.
    ];

    /**
     * Create provider instance by code.
     * Code có format/logic riêng thì dùng class tương ứng; còn lại
     * mặc định dùng GenericSmmProvider (chuẩn SMM panel: key/action/service/link/quantity).
     */
    public static function make(Provider $provider): ProviderInterface
    {
        $code = $provider->code;

        $class = self::$providers[$code] ?? GenericSmmProvider::class;

        return (new $class())->setProvider($provider);
    }

    public static function isSupported(string $code): bool
    {
        // Mọi provider đều được hỗ trợ: code đã đăng ký dùng class riêng,
        // còn lại fallback về GenericSmmProvider.
        return trim($code) !== '';
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
