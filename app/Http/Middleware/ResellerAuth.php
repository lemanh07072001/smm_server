<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResellerAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $apiKey = $request->header('X-Api-Key') ?? $request->bearerToken() ?? $request->input('api_key');

        if (!$apiKey) {
            return response()->json(['error' => 'API key is required'], 401);
        }

        $user = User::where('api_token', $apiKey)->where('is_active', true)->first();

        if (!$user) {
            return response()->json(['error' => 'Invalid API key'], 401);
        }

        if (!$user->isReseller()) {
            return response()->json(['error' => 'Access denied. Reseller account required.'], 403);
        }

        $request->setUserResolver(fn() => $user);

        return $next($request);
    }
}
