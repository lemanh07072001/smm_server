<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateSettingRequest;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingController extends Controller
{
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

    public function update(UpdateSettingRequest $request): JsonResponse
    {
        $settings = $request->validated()['settings'];

        foreach ($settings as $item) {
            Setting::where('key', $item['key'])->update(['value' => $item['value']]);
        }

        return response()->json([
            'message' => 'Cập nhật cài đặt thành công.',
            'data' => Setting::all(),
        ]);
    }

    public function destroy(string $key): JsonResponse
    {
        $setting = Setting::where('key', $key)->firstOrFail();
        $setting->delete();

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
