<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiKeyAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $apiKey = $request->input('key') ?? $request->header('X-Api-Key') ?? $request->bearerToken();

        if (!$apiKey) {
            return response()->json(['error' => 'API token is required'], 401);
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
