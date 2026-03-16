<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class ApiKeyAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        // Ưu tiên header (an toàn hơn query param — không bị ghi vào server logs)
        $apiKey = $request->header('X-Api-Key') ?? $request->bearerToken() ?? $request->input('key');

        if (!$apiKey) {
            return response()->json(['error' => 'API token is required'], 401);
        }

        // Cảnh báo nếu key truyền qua query string (không an toàn)
        if ($request->input('key') && !$request->header('X-Api-Key') && !$request->bearerToken()) {
            Log::warning('API key passed via query parameter (insecure)', [
                'ip'  => $request->ip(),
                'url' => $request->path(),
            ]);
        }

        $user = User::where('api_token', $apiKey)->first();

        if (!$user) {
            return response()->json(['error' => 'Invalid API key'], 401);
        }

        // Gán user vào request để controller dùng
        $request->merge(['_api_user' => $user]);
        $request->setUserResolver(fn() => $user);

        return $next($request);
    }
}
