<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Models\LoginHistory;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    /**
     * Register a new user.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => __('auth.register_success'),
            'user' => $user,
            'access_token' => $token,
            'token_type' => 'Bearer',
        ], 201);
    }

    /**
     * Login user and create token.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->email)->first();

        if (!$user) {
            $this->recordLoginHistory($request, null, 'failed');
            return response()->json([
                'message' => __('auth.login_failed'),
                'errors' => [
                    'email' => [__('auth.email_not_found')],
                ],
            ], 401);
        }

        if (!Hash::check($request->password, $user->password)) {
            $this->recordLoginHistory($request, $user->id, 'failed');
            return response()->json([
                'message' => __('auth.login_failed'),
                'errors' => [
                    'password' => [__('auth.password_incorrect')],
                ],
            ], 401);
        }

        $this->recordLoginHistory($request, $user->id, 'success');

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => __('auth.login_success'),
            'user' => $user,
            'access_token' => $token,
            'token_type' => 'Bearer',
        ]);
    }

    /**
     * Record login history.
     */
    private function recordLoginHistory(Request $request, ?int $userId, string $status): void
    {
        $userAgent = $request->userAgent();

        LoginHistory::create([
            'user_id' => $userId,
            'ip_address' => $request->ip(),
            'user_agent' => $userAgent,
            'device' => $this->getDevice($userAgent),
            'browser' => $this->getBrowser($userAgent),
            'platform' => $this->getPlatform($userAgent),
            'status' => $status,
            'login_at' => now(),
        ]);
    }

    /**
     * Get device type from user agent.
     */
    private function getDevice(?string $userAgent): string
    {
        if (!$userAgent) {
            return 'Unknown';
        }

        if (preg_match('/Mobile|Android|iPhone|iPad/i', $userAgent)) {
            if (preg_match('/iPad/i', $userAgent)) {
                return 'Tablet';
            }
            return 'Mobile';
        }

        return 'Desktop';
    }

    /**
     * Get browser from user agent.
     */
    private function getBrowser(?string $userAgent): string
    {
        if (!$userAgent) {
            return 'Unknown';
        }

        if (preg_match('/Edge|Edg/i', $userAgent)) {
            return 'Edge';
        }
        if (preg_match('/Chrome/i', $userAgent)) {
            return 'Chrome';
        }
        if (preg_match('/Firefox/i', $userAgent)) {
            return 'Firefox';
        }
        if (preg_match('/Safari/i', $userAgent)) {
            return 'Safari';
        }
        if (preg_match('/Opera|OPR/i', $userAgent)) {
            return 'Opera';
        }

        return 'Unknown';
    }

    /**
     * Get platform from user agent.
     */
    private function getPlatform(?string $userAgent): string
    {
        if (!$userAgent) {
            return 'Unknown';
        }

        if (preg_match('/Windows/i', $userAgent)) {
            return 'Windows';
        }
        if (preg_match('/Macintosh|Mac OS/i', $userAgent)) {
            return 'macOS';
        }
        if (preg_match('/Linux/i', $userAgent)) {
            return 'Linux';
        }
        if (preg_match('/Android/i', $userAgent)) {
            return 'Android';
        }
        if (preg_match('/iPhone|iPad|iOS/i', $userAgent)) {
            return 'iOS';
        }

        return 'Unknown';
    }

    /**
     * Logout user (revoke current token).
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => __('auth.logout_success'),
        ]);
    }

    /**
     * Get authenticated user profile.
     */
    public function profile(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $request->user(),
        ]);
    }
}
