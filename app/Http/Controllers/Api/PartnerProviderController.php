<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PartnerProvider;
use App\Models\Provider;
use App\Services\Providers\ProviderFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class PartnerProviderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = PartnerProvider::query();

        if ($request->has('is_allowed')) {
            $query->where('is_allowed', filter_var($request->input('is_allowed'), FILTER_VALIDATE_BOOLEAN));
        }

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%");
            });
        }

        $perPage = min((int) $request->input('per_page', 20), 100);
        $items = $query->orderBy('name')->paginate($perPage);

        return response()->json([
            'data'         => $items->items(),
            'total'        => $items->total(),
            'current_page' => $items->currentPage(),
            'per_page'     => $items->perPage(),
            'last_page'    => $items->lastPage(),
        ]);
    }

    public function all(): JsonResponse
    {
        $items = PartnerProvider::orderBy('name')->get(['id', 'name', 'code', 'is_allowed']);

        return response()->json(['data' => $items]);
    }

    /**
     * Danh sách đối tác được Super Admin cho phép (dành cho admin xem khi thêm provider).
     */
    public function allowed(): JsonResponse
    {
        $items = PartnerProvider::where('is_allowed', true)
            ->orderBy('name')
            ->get(['id', 'name', 'code']);

        return response()->json(['data' => $items]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code'        => ['required', 'string', 'max:50', 'regex:/^[a-z0-9_-]+$/', 'unique:partner_providers,code'],
            'name'        => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'is_allowed'  => ['required', 'boolean'],
        ]);

        if (!ProviderFactory::isSupported($validated['code'])) {
            return response()->json([
                'message' => "Code [{$validated['code']}] chưa được đăng ký trong ProviderFactory. Vui lòng đăng ký provider trước.",
            ], 422);
        }

        $item = PartnerProvider::create($validated);

        return response()->json(['message' => 'Tạo đối tác thành công.', 'data' => $item], 201);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(['data' => PartnerProvider::findOrFail($id)]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $item = PartnerProvider::findOrFail($id);

        $validated = $request->validate([
            'code'        => ['sometimes', 'string', 'max:50', 'regex:/^[a-z0-9_-]+$/', "unique:partner_providers,code,{$id}"],
            'name'        => ['sometimes', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'is_allowed'  => ['sometimes', 'boolean'],
        ]);

        $item->update($validated);

        if (isset($validated['is_allowed'])) {
            $this->syncProviders($item->code, $validated['is_allowed']);
        }

        return response()->json(['message' => 'Cập nhật đối tác thành công.', 'data' => $item]);
    }

    public function destroy(int $id): JsonResponse
    {
        PartnerProvider::findOrFail($id)->delete();

        return response()->json(['message' => 'Xóa đối tác thành công.']);
    }

    public function toggle(int $id): JsonResponse
    {
        $item = PartnerProvider::findOrFail($id);
        $item->update(['is_allowed' => !$item->is_allowed]);

        $affected = $this->syncProviders($item->code, $item->is_allowed);

        $status = $item->is_allowed ? 'cho phép' : 'không cho phép';
        $warning = $affected === 0 ? " (Không tìm thấy provider nào với code [{$item->code}] trong hệ thống)" : '';

        return response()->json(['message' => "Đã {$status} đối tác {$item->name}.{$warning}", 'data' => $item]);
    }

    private function syncProviders(string $code, bool $isAllowed): int
    {
        $affected = Provider::where('code', $code)->update(['is_active' => $isAllowed ? 1 : 0]);

        // Clear services cache luôn để thay đổi có hiệu lực ngay
        $cacheKeys = Cache::get('services_cache_keys', []);
        foreach ($cacheKeys as $key) {
            Cache::forget($key);
        }
        Cache::forget('services_cache_keys');

        return $affected;
    }
}
