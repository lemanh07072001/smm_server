<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class VerifySepayWebhook
{
    public function handle(Request $request, Closure $next): Response
    {
        // 1. API Key validation — bắt buộc phải cấu hình, không được để trống
        $expectedKey = config('services.sepay.api_key');

        if (empty($expectedKey)) {
            Log::error('SePay webhook: SEPAY_API_KEY chưa được cấu hình. Từ chối toàn bộ request.');
            return response()->json(['success' => false], 500);
        }

        $authHeader = $request->header('Authorization', '');

        // SePay gửi header: "Apikey YOUR_KEY"
        $providedKey = null;
        if (str_starts_with($authHeader, 'Apikey ')) {
            $providedKey = substr($authHeader, 7);
        }

        if (!$providedKey || !hash_equals($expectedKey, $providedKey)) {
            Log::warning('SePay webhook: Invalid API key', [
                'ip' => $request->ip(),
            ]);
            return response()->json(['success' => false], 401);
        }

        // 2. IP whitelist (tùy chọn)
        $allowedIps = config('services.sepay.allowed_ips', []);

        if (!empty($allowedIps) && !in_array($request->ip(), $allowedIps)) {
            Log::warning('SePay webhook: IP not allowed', [
                'ip' => $request->ip(),
            ]);
            return response()->json(['success' => false], 403);
        }

        return $next($request);
    }
}
