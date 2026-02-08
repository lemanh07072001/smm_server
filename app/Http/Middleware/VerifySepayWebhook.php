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
        // 1. API Key validation
        $expectedKey = config('services.sepay.api_key');

        if ($expectedKey) {
            $authHeader = $request->header('Authorization', '');

            // SePay gui header: "Apikey YOUR_KEY"
            $providedKey = null;
            if (str_starts_with($authHeader, 'Apikey ')) {
                $providedKey = substr($authHeader, 7);
            }

            if (!$providedKey || $providedKey !== $expectedKey) {
                Log::warning('SePay webhook: Invalid API key', [
                    'ip' => $request->ip(),
                ]);
                return response()->json(['success' => false], 401);
            }
        }

        // 2. IP whitelist (tuy chon)
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
