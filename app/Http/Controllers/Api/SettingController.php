<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
// UpdateSettingRequest removed — validate inline to avoid exists:settings,key constraint
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class SettingController extends Controller
{
    private const CACHE_KEY = 'settings_public';

    public function publicIndex(Request $request): JsonResponse
    {
        $group = $request->input('group');
        $cacheKey = $group ? self::CACHE_KEY . '_' . $group : self::CACHE_KEY;

        $data = Cache::rememberForever($cacheKey, function () use ($group) {
            $query = Setting::orderBy('group')->orderBy('key');
            if ($group) {
                $query->where('group', $group);
            }
            return $query->get();
        });

        return response()->json([
            'data' => $data,
        ]);
    }

    private function clearCache(): void
    {
        // Xoá cache chính và tất cả cache theo group
        $keys = Cache::get('settings_cache_keys', []);
        foreach ($keys as $key) {
            Cache::forget($key);
        }
        Cache::forget(self::CACHE_KEY);
        Cache::forget('settings_cache_keys');
    }

    public function index(Request $request): JsonResponse
    {
        $group = $request->input('group');

        $query = Setting::orderBy('group')->orderBy('key');

        if ($group) {
            $query->where('group', $group);
        }

        return response()->json([
            'data' => $query->get(),
        ]);
    }

    public function save(Request $request): JsonResponse
    {
        $request->validate([
            'key' => ['required', 'string', 'max:100'],
            'value' => ['nullable', 'string', 'max:2000'],
            'group' => ['required', 'string', 'max:50'],
        ], [
            'key.required' => 'Key là bắt buộc.',
            'group.required' => 'Nhóm là bắt buộc.',
        ]);

        $setting = Setting::updateOrCreate(
            ['key' => $request->input('key')],
            $request->only(['value', 'group'])
        );

        $isNew = $setting->wasRecentlyCreated;
        $this->clearCache();

        return response()->json([
            'message' => $isNew ? 'Tạo cài đặt thành công.' : 'Cập nhật cài đặt thành công.',
            'data' => $setting,
        ], $isNew ? 201 : 200);
    }

    public function show(string $key): JsonResponse
    {
        $setting = Setting::where('key', $key)->firstOrFail();

        return response()->json([
            'data' => $setting,
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $request->validate([
            'settings'         => ['required', 'array', 'min:1'],
            'settings.*.key'   => ['required', 'string', 'max:100'],
            'settings.*.value' => ['nullable', 'string'],
            'settings.*.group' => ['nullable', 'string', 'max:50'],
        ]);

        $settings = $request->input('settings');

        foreach ($settings as $item) {
            Setting::updateOrCreate(
                ['key' => $item['key']],
                [
                    'value' => $item['value'] ?? null,
                    'group' => $item['group'] ?? 'general',
                ]
            );
        }

        $this->clearCache();

        return response()->json([
            'message' => 'Cập nhật cài đặt thành công.',
        ]);
    }

    public function destroy(string $key): JsonResponse
    {
        $setting = Setting::where('key', $key)->firstOrFail();
        $setting->delete();
        $this->clearCache();

        return response()->json([
            'message' => 'Xóa cài đặt thành công.',
        ]);
    }

    public function all(): JsonResponse
    {
        $settings = Setting::all()->groupBy('group')->map(function ($group) {
            return $group->pluck('value', 'key');
        });

        return response()->json([
            'data' => $settings,
        ]);
    }
}
