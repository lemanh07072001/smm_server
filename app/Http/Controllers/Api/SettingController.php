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

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'key' => ['required', 'string', 'max:100', 'unique:settings,key'],
            'value' => ['nullable', 'string', 'max:2000'],
            'group' => ['required', 'string', 'max:50'],
        ], [
            'key.required' => 'Key là bắt buộc.',
            'key.unique' => 'Key đã tồn tại.',
            'group.required' => 'Nhóm là bắt buộc.',
        ]);

        $setting = Setting::create($request->only(['key', 'value', 'group']));

        return response()->json([
            'message' => 'Tạo cài đặt thành công.',
            'data' => $setting,
        ], 201);
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

    public function updateSingle(Request $request, string $key): JsonResponse
    {
        $setting = Setting::where('key', $key)->firstOrFail();

        $request->validate([
            'key' => ['sometimes', 'string', 'max:100', 'unique:settings,key,' . $setting->id],
            'value' => ['nullable', 'string', 'max:2000'],
            'group' => ['sometimes', 'string', 'max:50'],
        ], [
            'key.unique' => 'Key đã tồn tại.',
        ]);

        $setting->update($request->only(['key', 'value', 'group']));

        return response()->json([
            'message' => 'Cập nhật cài đặt thành công.',
            'data' => $setting,
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
