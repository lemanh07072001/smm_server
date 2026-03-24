<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ForgotPasswordRequest;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Requests\ResetPasswordRequest;
use App\Models\LoginHistory;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    /**
     * Register a new user.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $userData = [
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ];

        if ($request->ref) {
            $refId = (int) $request->ref;
            // Chỉ gán nếu referrer tồn tại và không phải là chính user mới này
            if ($refId > 0 && User::where('id', $refId)->exists()) {
                $userData['referred_by'] = $refId;
            }
        }

        $user = User::create($userData);

        // Tự động tạo mã giao dịch cho user mới đăng ký
        $this->createInitialTransactionCode($user);

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => __('auth.register_success'),
            'user' => $user,
            'access_token' => $token,
            'token_type' => 'Bearer',
        ], 201);
    }

    /**
     * Tạo mã nạp tiền cố định cho user mới đăng ký.
     * Format: NAP + 6 chữ số ngẫu nhiên, unique trên DB.
     * Retry tối đa 10 lần nếu trùng.
     */
    private function createInitialTransactionCode(User $user): void
    {
        try {
            $code = null;
            $maxAttempts = 10;

            for ($i = 0; $i < $maxAttempts; $i++) {
                $candidate = 'NAP' . str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                $exists = User::where('deposit_code', $candidate)->exists();
                if (!$exists) {
                    $code = $candidate;
                    break;
                }
            }

            if ($code) {
                $user->deposit_code = $code;
                $user->saveQuietly(); // không fire events
            }
        } catch (\Exception $e) {
            logger()->error('Register: failed to create deposit_code', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);
        }
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

        // Tạo api_token nếu chưa có hoặc chưa phải format Sanctum ({id}|{hash})
        if (!$user->api_token || !str_contains($user->api_token, '|')) {
            // Xóa sanctum token api cũ nếu có
            $user->tokens()->where('name', 'api_token')->delete();
            // Tạo Sanctum token và lưu plaintext vào cột api_token
            $apiTokenPlain = $user->createToken('api_token')->plainTextToken;
            $user->update(['api_token' => $apiTokenPlain]);
            $user->refresh();
        }

        return response()->json([
            'message' => __('auth.login_success'),
            'user' => $user,
            'access_token' => $token,
            'token_type' => 'Bearer',
            'api_token' => $user->api_token,
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

    /**
     * Send password reset link to user's email.
     */
    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $user = User::where('email', $request->email)->first();

        // Không lộ email có tồn tại hay không — luôn trả cùng message
        if (!$user) {
            return response()->json([
                'message' => 'Link đặt lại mật khẩu đã được gửi đến email của bạn.',
            ]);
        }

        // Delete old tokens for this email
        DB::table('password_reset_tokens')->where('email', $request->email)->delete();

        // Create new token
        $token = Str::random(64);

        // Store token in database
        DB::table('password_reset_tokens')->insert([
            'email' => $request->email,
            'token' => Hash::make($token),
            'created_at' => now(),
        ]);

        // Send email notification
        $user->notify(new ResetPasswordNotification($token));

        return response()->json([
            'message' => 'Link đặt lại mật khẩu đã được gửi đến email của bạn.',
        ]);
    }

    /**
     * Reset user's password.
     */
    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        // Get token record from database
        $tokenData = DB::table('password_reset_tokens')
            ->where('email', $request->email)
            ->first();

        // Check if token exists
        if (!$tokenData) {
            return response()->json([
                'message' => 'Token không hợp lệ.',
                'errors' => [
                    'token' => ['Token không tồn tại hoặc đã hết hạn.'],
                ],
            ], 422);
        }

        // Check if token is expired (60 minutes)
        if (now()->diffInMinutes($tokenData->created_at) > 60) {
            DB::table('password_reset_tokens')->where('email', $request->email)->delete();

            return response()->json([
                'message' => 'Token đã hết hạn.',
                'errors' => [
                    'token' => ['Token đã hết hạn, vui lòng yêu cầu đặt lại mật khẩu mới.'],
                ],
            ], 422);
        }

        // Verify token
        if (!Hash::check($request->token, $tokenData->token)) {
            return response()->json([
                'message' => 'Token không hợp lệ.',
                'errors' => [
                    'token' => ['Token không chính xác.'],
                ],
            ], 422);
        }

        // Update user's password
        $user = User::where('email', $request->email)->first();
        $user->password = Hash::make($request->password);
        $user->save();

        // Delete token after successful reset
        DB::table('password_reset_tokens')->where('email', $request->email)->delete();

        // Revoke all existing tokens for security
        $user->tokens()->delete();

        return response()->json([
            'message' => 'Mật khẩu đã được đặt lại thành công.',
        ]);
    }
}
