<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreServiceRequest;
use App\Http\Requests\UpdateServiceRequest;
use App\Models\CategoryGroup;
use App\Models\Service;
use App\Models\ServiceAgentPrice;
use App\Models\ServicePriceHistory;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ServiceController extends Controller
{
    /**
     * Clear all services cache
     */
    private function clearServicesCache(): void
    {
        // Clear all cache keys that start with 'services_group_'
        $cacheKeys = Cache::get('services_cache_keys', []);
        foreach ($cacheKeys as $key) {
            Cache::forget($key);
        }
        Cache::forget('services_cache_keys');
    }

    /**
     * Store cache key for tracking
     */
    private function storeCacheKey(string $key): void
    {
        $cacheKeys = Cache::get('services_cache_keys', []);
        if (!in_array($key, $cacheKeys)) {
            $cacheKeys[] = $key;
            Cache::put('services_cache_keys', $cacheKeys, 3600);
        }
    }

    public function index(Request $request): JsonResponse
    {
        $limit = $request->input('limit', 10);
        $page = $request->input('page', 1);
        $search = $request->input('search');
        $status = $request->input('is_active');
        $categoryGroupId = $request->input('category_group_id');
        $providerServiceId = $request->input('provider_service_id');

        $query = Service::with(['providerService.provider'])
            ->orderBy('sort_order', 'asc')
            ->orderBy('created_at', 'desc');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if ($status !== null) {
            $query->where('is_active', $status === '1' || $status === 'true' ? 1 : 0);
        }

        if ($categoryGroupId !== null) {
            $query->where('category_group_id', $categoryGroupId);
        }

        if ($providerServiceId !== null) {
            $query->where('provider_service_id', $providerServiceId);
        }

        $total = $query->count();
        $totalPages = (int) ceil($total / $limit);

        $services = $query->skip(($page - 1) * $limit)
            ->take($limit)
            ->get();

        return response()->json([
            'data' => $services,
            'total' => $total,
            'page' => (int) $page,
            'limit' => (int) $limit,
            'totalPages' => $totalPages,
        ]);
    }

    public function store(StoreServiceRequest $request): JsonResponse
    {
        $data = $request->validated();

        $service = Service::create($data);
        $service->load(['categoryGroup', 'providerService']);

        // Clear cache after creating new service
        $this->clearServicesCache();

        return response()->json([
            'message' => 'Tạo dịch vụ thành công.',
            'data' => $service,
        ], 201);
    }

    public function show(string $id): JsonResponse
    {
        $service = Service::with(['categoryGroup', 'providerService'])->findOrFail($id);

        return response()->json([
            'data' => $service,
        ]);
    }

    public function update(UpdateServiceRequest $request, string $id): JsonResponse
    {
        $service = Service::findOrFail($id);
        $data = $request->validated();

        $service->update($data);
        $service->load(['categoryGroup', 'providerService']);

        // Clear cache after updating service
        $this->clearServicesCache();

        return response()->json([
            'message' => 'Cập nhật dịch vụ thành công.',
            'data' => $service,
        ]);
    }

    public function destroy(string $id): JsonResponse
    {
        $service = Service::findOrFail($id);
        $service->delete();

        // Clear cache after deleting service
        $this->clearServicesCache();

        return response()->json([
            'message' => 'Xóa dịch vụ thành công.',
        ]);
    }

    public function destroyMultiple(Request $request): JsonResponse
    {
        $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'integer', 'exists:services,id'],
        ]);

        $count = Service::whereIn('id', $request->ids)->delete();

        // Clear cache after deleting multiple services
        $this->clearServicesCache();

        return response()->json([
            'message' => "Đã xóa {$count} dịch vụ thành công.",
        ]);
    }

    public function platforms(): JsonResponse
    {
        return response()->json([
            'data' => Service::PLATFORM,
        ]);
    }

    public function all(): JsonResponse
    {
        $categoryGroups = CategoryGroup::where('is_active', 1)
            ->orderBy('sort_order', 'asc')
            ->orderBy('name', 'asc')
            // ->with(['services' => function ($query) {
            //     $query->where('is_active', 1)
            //         ->with(['categoryGroup', 'providerService'])
            //         ->orderBy('sort_order', 'asc')
            //         ->orderBy('name', 'asc');
            // }])
            ->get();

        return response()->json([
            'data' => $categoryGroups,
        ]);
    }

    public function formTypes(): JsonResponse
    {
        $data = Cache::remember('service_form_types', 3600, function () {
            return [
                'feel_form' => Service::FEEL_FORM,
                'comment_form' => Service::COMMENT_FORM,
            ];
        });

        return response()->json([
            'data' => $data,
        ]);
    }

    public function getAgentPrices(string $id): JsonResponse
    {
        $service = Service::findOrFail($id);
        $prices = $service->agentPrices()->orderBy('agent_level')->get();

        // Build danh sách đầy đủ tất cả cấp đại lý, cấp nào chưa có giá thì null
        $data = collect(User::AGENT_LEVELS)->map(function ($level) use ($prices) {
            $price = $prices->firstWhere('agent_level', $level);
            return [
                'agent_level' => $level,
                'sell_rate'   => $price ? (float) $price->sell_rate : null,
                'is_set'      => $price !== null,
            ];
        });

        return response()->json([
            'service' => [
                'id'        => $service->id,
                'name'      => $service->name,
                'sell_rate' => (float) $service->sell_rate,
            ],
            'agent_levels' => User::AGENT_LEVELS,
            'data'         => $data,
        ]);
    }

    public function updateAgentPrice(Request $request, string $id, int $level): JsonResponse
    {
        $request->validate([
            'sell_rate' => ['required', 'numeric', 'min:0'],
        ], [
            'sell_rate.required' => 'Giá bán là bắt buộc.',
            'sell_rate.numeric'  => 'Giá bán phải là số.',
            'sell_rate.min'      => 'Giá bán phải lớn hơn hoặc bằng 0.',
        ]);

        if (!in_array($level, User::AGENT_LEVELS)) {
            return response()->json([
                'message'      => 'Cấp đại lý không hợp lệ.',
                'agent_levels' => User::AGENT_LEVELS,
            ], 422);
        }

        $service = Service::findOrFail($id);
        $admin = $request->user();

        $existing = ServiceAgentPrice::where('service_id', $service->id)
            ->where('agent_level', $level)
            ->first();

        $oldPrice = $existing ? (float) $existing->sell_rate : null;
        $newPrice = (float) $request->sell_rate;

        ServiceAgentPrice::updateOrCreate(
            ['service_id' => $service->id, 'agent_level' => $level],
            ['sell_rate' => $newPrice]
        );

        ServicePriceHistory::create([
            'service_id'  => $service->id,
            'agent_level' => $level,
            'old_price'   => $oldPrice ?? $newPrice,
            'new_price'   => $newPrice,
            'changed_by'  => $admin->id,
        ]);

        $this->clearServicesCache();

        // Trả về đầy đủ tất cả giá của service sau khi cập nhật
        $allPrices = $service->agentPrices()->orderBy('agent_level')->get();
        $data = collect(User::AGENT_LEVELS)->map(function ($lvl) use ($allPrices) {
            $price = $allPrices->firstWhere('agent_level', $lvl);
            return [
                'agent_level' => $lvl,
                'sell_rate'   => $price ? (float) $price->sell_rate : null,
                'is_set'      => $price !== null,
            ];
        });

        return response()->json([
            'message' => "Đã cập nhật giá đại lý cấp {$level} thành công.",
            'updated' => [
                'agent_level' => $level,
                'old_price'   => $oldPrice,
                'new_price'   => $newPrice,
                'changed_by'  => [
                    'id'    => $admin->id,
                    'name'  => $admin->name,
                    'email' => $admin->email,
                ],
            ],
            'service' => [
                'id'        => $service->id,
                'name'      => $service->name,
                'sell_rate' => (float) $service->sell_rate,
            ],
            'data' => $data,
        ]);
    }

    /**
     * Cập nhật giá nhiều cấp đại lý cùng lúc.
     * Body: { "prices": { "1": "1000", "2": "900", "3": "", "4": "" } }
     * Cấp nào để trống ("" hoặc null) thì bỏ qua, không cập nhật.
     */
    public function updateAgentPrices(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'prices'   => ['required', 'array'],
            'prices.*' => ['nullable', 'numeric', 'min:0'],
        ], [
            'prices.required'  => 'Danh sách giá là bắt buộc.',
            'prices.*.numeric' => 'Giá bán phải là số.',
            'prices.*.min'     => 'Giá bán phải lớn hơn hoặc bằng 0.',
        ]);

        $service = Service::findOrFail($id);
        $admin   = $request->user();
        $updated = [];

        foreach ($request->prices as $level => $rate) {
            $level = (int) $level;

            // Bỏ qua cấp không hợp lệ
            if (!in_array($level, User::AGENT_LEVELS)) {
                continue;
            }

            $existing = ServiceAgentPrice::where('service_id', $service->id)
                ->where('agent_level', $level)
                ->first();

            $oldPrice = $existing ? (float) $existing->sell_rate : null;

            // Nếu rate để trống → xóa giá cấp đó
            if ($rate === null || $rate === '') {
                if ($existing) {
                    $existing->delete();
                    $updated[] = [
                        'agent_level' => $level,
                        'old_price'   => $oldPrice,
                        'new_price'   => null,
                        'action'      => 'deleted',
                    ];
                }
                continue;
            }

            $newPrice = (float) $rate;

            ServiceAgentPrice::updateOrCreate(
                ['service_id' => $service->id, 'agent_level' => $level],
                ['sell_rate'  => $newPrice]
            );

            ServicePriceHistory::create([
                'service_id'  => $service->id,
                'agent_level' => $level,
                'old_price'   => $oldPrice ?? $newPrice,
                'new_price'   => $newPrice,
                'changed_by'  => $admin->id,
            ]);

            $updated[] = [
                'agent_level' => $level,
                'old_price'   => $oldPrice,
                'new_price'   => $newPrice,
                'action'      => $oldPrice === null ? 'created' : 'updated',
            ];
        }

        $this->clearServicesCache();

        $allPrices = $service->agentPrices()->orderBy('agent_level')->get();
        $data = collect(User::AGENT_LEVELS)->map(function ($lvl) use ($allPrices) {
            $price = $allPrices->firstWhere('agent_level', $lvl);
            return [
                'agent_level' => $lvl,
                'sell_rate'   => $price ? (float) $price->sell_rate : null,
                'is_set'      => $price !== null,
            ];
        });

        return response()->json([
            'message' => 'Cập nhật giá đại lý thành công.',
            'updated_count' => count($updated),
            'updated' => $updated,
            'changed_by' => [
                'id'    => $admin->id,
                'name'  => $admin->name,
                'email' => $admin->email,
            ],
            'service' => [
                'id'        => $service->id,
                'name'      => $service->name,
                'sell_rate' => (float) $service->sell_rate,
            ],
            'data' => $data,
        ]);
    }

    public function getPriceHistory(string $id): JsonResponse
    {
        $service = Service::findOrFail($id);

        $history = ServicePriceHistory::where('service_id', $service->id)
            ->with('changedBy:id,name,email')
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'data' => $history,
        ]);
    }

    public function getByGroupId(Request $request, ?string $groupId = null): JsonResponse
    {
        // Get filter parameters
        $isActive = $request->input('is_active');
        $categoryGroupId = $request->input('category_group_id');

        // Create cache key based on parameters
        $cacheKey = 'services_group_' .
                    ($groupId ?? 'all') . '_' .
                    ($isActive ?? 'any') . '_' .
                    ($categoryGroupId ?? 'any');

        // Store cache key for tracking
        $this->storeCacheKey($cacheKey);

        // Cache for 1 hour (3600 seconds)
        $data = Cache::remember($cacheKey, 3600, function () use ($groupId, $isActive, $categoryGroupId) {
            $query = Service::with(['categoryGroup', 'providerService', 'country'])
                ->orderBy('sort_order', 'asc')
                ->orderBy('name', 'asc');

            // Filter by group_id if provided
            if ($groupId !== null && $groupId !== 'all') {
                $query->where('group_id', $groupId);
            }

            // Filter by is_active if provided
            if ($isActive !== null) {
                $query->where('is_active', $isActive === 1 || $isActive === 'true' ? 1 : 0);
            }

            // Filter by category_group_id if provided
            if ($categoryGroupId !== null) {
                $query->where('category_group_id', $categoryGroupId);
            }

            $services = $query->get();

            return [
                'data' => $services,
                'total' => $services->count(),
                'group_id' => $groupId,
            ];
        });

        return response()->json($data);
    }
}
