<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Provider;
use App\Models\ResellerProviderPermission;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ResellerPermissionController extends Controller
{
    /**
     * Danh sách tất cả reseller với permissions của họ.
     */
    public function index(Request $request): JsonResponse
    {
        $resellers = User::where('role', User::ROLE_RESELLER)
            ->with(['resellerProviderPermissions.provider'])
            ->get()
            ->map(function ($user) {
                return [
                    'id'          => $user->id,
                    'name'        => $user->name,
                    'email'       => $user->email,
                    'balance'     => (float) $user->balance,
                    'is_active'   => $user->is_active,
                    'permissions' => $user->resellerProviderPermissions->map(fn($p) => [
                        'provider_id'   => $p->provider_id,
                        'provider_code' => $p->provider?->code,
                        'provider_name' => $p->provider?->name,
                        'is_allowed'    => $p->is_allowed,
                    ]),
                ];
            });

        return response()->json(['data' => $resellers]);
    }

    /**
     * Lấy permissions của một reseller cụ thể.
     */
    public function show(int $userId): JsonResponse
    {
        $user = User::where('id', $userId)->where('role', User::ROLE_RESELLER)->first();
        if (!$user) {
            return response()->json(['message' => 'Reseller không tồn tại.'], 404);
        }

        $allProviders = Provider::where('is_active', true)->get();

        $permissionMap = ResellerProviderPermission::where('user_id', $userId)
            ->pluck('is_allowed', 'provider_id');

        $data = $allProviders->map(fn($provider) => [
            'provider_id'   => $provider->id,
            'provider_code' => $provider->code,
            'provider_name' => $provider->name,
            'is_allowed'    => $permissionMap->get($provider->id, false),
        ]);

        return response()->json([
            'reseller'    => [
                'id'    => $user->id,
                'name'  => $user->name,
                'email' => $user->email,
            ],
            'permissions' => $data,
        ]);
    }

    /**
     * Cập nhật permissions cho một reseller.
     * Body: { permissions: [{ provider_id: int, is_allowed: bool }] }
     */
    public function update(Request $request, int $userId): JsonResponse
    {
        $request->validate([
            'permissions'                => ['required', 'array'],
            'permissions.*.provider_id'  => ['required', 'integer', 'exists:providers,id'],
            'permissions.*.is_allowed'   => ['required', 'boolean'],
        ]);

        $user = User::where('id', $userId)->where('role', User::ROLE_RESELLER)->first();
        if (!$user) {
            return response()->json(['message' => 'Reseller không tồn tại.'], 404);
        }

        foreach ($request->permissions as $perm) {
            ResellerProviderPermission::updateOrCreate(
                ['user_id' => $userId, 'provider_id' => $perm['provider_id']],
                ['is_allowed' => $perm['is_allowed']]
            );
        }

        return response()->json(['message' => 'Cập nhật quyền thành công.']);
    }

    /**
     * Toggle một provider permission cho reseller.
     */
    public function toggle(Request $request, int $userId, int $providerId): JsonResponse
    {
        $user = User::where('id', $userId)->where('role', User::ROLE_RESELLER)->first();
        if (!$user) {
            return response()->json(['message' => 'Reseller không tồn tại.'], 404);
        }

        $provider = Provider::find($providerId);
        if (!$provider) {
            return response()->json(['message' => 'Provider không tồn tại.'], 404);
        }

        $permission = ResellerProviderPermission::firstOrNew([
            'user_id'     => $userId,
            'provider_id' => $providerId,
        ]);

        $permission->is_allowed = !($permission->is_allowed ?? false);
        $permission->save();

        $this->clearPermissionCache($userId, $providerId);

        return response()->json([
            'message'    => $permission->is_allowed ? 'Đã cấp quyền.' : 'Đã thu hồi quyền.',
            'is_allowed' => $permission->is_allowed,
        ]);
    }

    /**
     * Cấp tất cả providers cho một reseller.
     */
    public function grantAll(int $userId): JsonResponse
    {
        $user = User::where('id', $userId)->where('role', User::ROLE_RESELLER)->first();
        if (!$user) {
            return response()->json(['message' => 'Reseller không tồn tại.'], 404);
        }

        $providers = Provider::where('is_active', true)->pluck('id');

        foreach ($providers as $providerId) {
            ResellerProviderPermission::updateOrCreate(
                ['user_id' => $userId, 'provider_id' => $providerId],
                ['is_allowed' => true]
            );
            $this->clearPermissionCache($userId, $providerId);
        }

        return response()->json(['message' => 'Đã cấp tất cả quyền.']);
    }

    /**
     * Thu hồi tất cả providers của một reseller.
     */
    public function revokeAll(int $userId): JsonResponse
    {
        $user = User::where('id', $userId)->where('role', User::ROLE_RESELLER)->first();
        if (!$user) {
            return response()->json(['message' => 'Reseller không tồn tại.'], 404);
        }

        $providerIds = ResellerProviderPermission::where('user_id', $userId)->pluck('provider_id');
        ResellerProviderPermission::where('user_id', $userId)->update(['is_allowed' => false]);

        foreach ($providerIds as $providerId) {
            $this->clearPermissionCache($userId, $providerId);
        }

        return response()->json(['message' => 'Đã thu hồi tất cả quyền.']);
    }

    private function clearPermissionCache(int $userId, int $providerId): void
    {
        Cache::forget("reseller_permission_{$userId}_{$providerId}");
    }
}
