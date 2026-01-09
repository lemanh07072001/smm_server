<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\Request;

class Authenticate extends Middleware
{
    /**
     * Get the path the user should be redirected to when they are not authenticated.
     */
    protected function redirectTo(Request $request): ?string
    {
        // Không redirect cho API requests hoặc broadcasting auth
        if ($request->expectsJson() || $request->is('api/*') || $request->is('broadcasting/*')) {
            return null;
        }

        return route('login');
    }
}
